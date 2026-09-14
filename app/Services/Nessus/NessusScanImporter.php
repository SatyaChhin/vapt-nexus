<?php

namespace App\Services\Nessus;

use App\Enums\ScanStatus;
use App\Enums\Severity;
use App\Jobs\ImportNessusScan;
use App\Models\NessusServer;
use App\Models\Project;
use App\Models\RawScanResult;
use App\Models\Scan;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\VulnerabilityInstance;
use App\Services\AuditLogger;
use App\Services\Nessus\Exceptions\NessusException;
use App\Services\Nessus\Exceptions\NessusRequestException;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Imports scans that were created and run in the Nessus UI.
 *
 * Only read endpoints are used (GET /scans, /scans/{id}, host and plugin
 * details), so this works on Nessus Essentials, whose license blocks
 * creating, launching and exporting scans through the API.
 */
class NessusScanImporter
{
    /** An import that has not finished after this long is treated as dead. */
    private const STALE_AFTER_MINUTES = 15;

    /** @var array<int, Vulnerability> */
    private array $plugins = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Scans on each Nessus server assigned to the project, flagged with their
     * import state. A server that cannot be reached reports its own error
     * instead of failing the whole list.
     *
     * @return list<array<string, mixed>>
     */
    public function available(Project $project): array
    {
        return $project->nessusServers()->orderBy('name')->get()
            ->map(function (NessusServer $server) use ($project) {
                $result = ['id' => $server->id, 'name' => $server->name, 'error' => null, 'scans' => []];

                try {
                    $response = NessusClient::forServer($server)->scans();
                } catch (NessusException $e) {
                    return [...$result, 'error' => $e->getMessage()];
                }

                $folders = collect($response['folders'] ?? [])->keyBy('id');
                $imported = Scan::query()
                    ->where('nessus_server_id', $server->id)
                    ->whereNotNull('nessus_scan_id')
                    ->get(['id', 'project_id', 'nessus_scan_id', 'status'])
                    ->keyBy('nessus_scan_id');

                $result['scans'] = collect($response['scans'] ?? [])
                    ->filter(fn ($scan) => is_array($scan) && isset($scan['id']))
                    ->map(function (array $scan) use ($folders, $imported, $project) {
                        $folder = $folders->get($scan['folder_id'] ?? null);
                        $existing = $imported->get((int) $scan['id']);
                        $ours = $existing !== null && $existing->project_id === $project->id;

                        return [
                            'id' => (int) $scan['id'],
                            'name' => (string) ($scan['name'] ?? 'Scan '.$scan['id']),
                            'status' => (string) ($scan['status'] ?? 'unknown'),
                            'folder' => $folder['name'] ?? null,
                            'in_trash' => ($folder['type'] ?? null) === 'trash',
                            'last_modified_at' => $this->time($scan['last_modification_date'] ?? null)?->toIso8601String(),
                            'scan_id' => $ours ? $existing->id : null,
                            'scan_status' => $ours ? $existing->status->value : null,
                            // Only a flag: the other project's name is not ours to show.
                            'imported_elsewhere' => $existing !== null && ! $ours,
                        ];
                    })
                    ->sortBy([['in_trash', 'asc'], ['last_modified_at', 'desc']])
                    ->values()
                    ->all();

                return $result;
            })
            ->all();
    }

    /**
     * Registers a Nessus scan in the project (or reuses its existing record)
     * and queues the import.
     */
    public function queue(Project $project, NessusServer $server, int $nessusScanId, User $user): Scan
    {
        if (! $project->nessusServers()->whereKey($server->id)->exists()) {
            throw ValidationException::withMessages([
                'nessus_server_id' => 'This Nessus server is not assigned to the project.',
            ]);
        }

        $scan = Scan::query()
            ->where('nessus_server_id', $server->id)
            ->where('nessus_scan_id', $nessusScanId)
            ->first();

        if ($scan !== null && $scan->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'nessus_scan_id' => 'This Nessus scan is already imported into another project.',
            ]);
        }

        if ($scan === null) {
            $info = $this->scanInfo($server, $nessusScanId);

            $scan = $project->scans()->make([
                'name' => Str::limit((string) ($info['name'] ?? "Nessus scan {$nessusScanId}"), 150, ''),
                'targets' => (string) ($info['targets'] ?? ''),
                'nessus_server_id' => $server->id,
            ]);
            $scan->nessus_scan_id = $nessusScanId;
            $scan->created_by = $user->id;
        }

        return $this->dispatch($scan, $user);
    }

    /**
     * Pulls the latest results of an already imported scan again.
     */
    public function resync(Scan $scan, User $user): Scan
    {
        if ($scan->nessus_server_id === null || $scan->nessus_scan_id === null) {
            throw ValidationException::withMessages([
                'scan' => 'This scan is no longer linked to a Nessus server.',
            ]);
        }

        return $this->dispatch($scan, $user);
    }

    public function import(Scan $scan, ?User $user = null): void
    {
        $server = $scan->nessusServer;

        if ($server === null || $scan->nessus_scan_id === null) {
            $this->fail($scan, 'This scan is no longer linked to a Nessus server.', $user);

            return;
        }

        $scan->forceFill(['status' => ScanStatus::Importing, 'error_message' => null])->save();

        try {
            $bundle = $this->fetch(NessusClient::forServer($server), $scan->nessus_scan_id);
        } catch (NessusException $e) {
            $this->fail($scan, $e->getMessage(), $user);

            return;
        }

        $this->plugins = [];
        DB::transaction(fn () => $this->persist($scan, $bundle));
        $this->storeRaw($scan, $bundle);

        $this->audit->log('scan.imported', $scan, metadata: [
            'nessus_server_id' => $scan->nessus_server_id,
            'nessus_scan_id' => $scan->nessus_scan_id,
            'nessus_status' => $bundle['scan']['info']['status'] ?? null,
            'hosts' => $scan->scanned_hosts,
            'findings' => $scan->total_findings,
        ], user: $user);
    }

    public function fail(Scan $scan, string $message, ?User $user = null): void
    {
        $scan->forceFill([
            'status' => ScanStatus::Failed,
            'error_message' => Str::limit($message, 500, ''),
        ])->save();

        $this->audit->log('scan.import_failed', $scan, metadata: ['message' => $message], user: $user);
    }

    private function dispatch(Scan $scan, User $user): Scan
    {
        $busy = in_array($scan->status, [ScanStatus::Queued, ScanStatus::Importing], true)
            && $scan->updated_at?->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));

        if ($busy) {
            throw ValidationException::withMessages([
                'nessus_scan_id' => 'This scan is already being imported.',
            ]);
        }

        $scan->forceFill(['status' => ScanStatus::Queued, 'error_message' => null])->save();

        $this->audit->log('scan.import_queued', $scan, metadata: [
            'nessus_server_id' => $scan->nessus_server_id,
            'nessus_scan_id' => $scan->nessus_scan_id,
        ], user: $user);

        ImportNessusScan::dispatch($scan, $user);

        return $scan->refresh();
    }

    /**
     * Checks up front that the scan exists and has been run, so the user gets
     * the error immediately instead of a failed import later.
     *
     * @return array<string, mixed>
     */
    private function scanInfo(NessusServer $server, int $nessusScanId): array
    {
        try {
            $info = NessusClient::forServer($server)->scanDetails($nessusScanId)['info'] ?? [];
        } catch (NessusRequestException $e) {
            throw ValidationException::withMessages([
                'nessus_scan_id' => $e->status === 404 ? 'This scan does not exist in Nessus.' : $e->getMessage(),
            ]);
        } catch (NessusException $e) {
            throw ValidationException::withMessages(['nessus_scan_id' => $e->getMessage()]);
        }

        if (($info['status'] ?? null) === 'empty') {
            throw ValidationException::withMessages([
                'nessus_scan_id' => 'This scan has never been run in Nessus, so there are no results to import.',
            ]);
        }

        return is_array($info) ? $info : [];
    }

    /**
     * Everything the import needs, fetched before touching the database.
     *
     * @return array{scan: array<string, mixed>, hosts: list<array{summary: array<string, mixed>, details: array<string, mixed>, plugins: array<int, array<string, mixed>>}>}
     */
    private function fetch(NessusClient $client, int $nessusScanId): array
    {
        $details = $client->scanDetails($nessusScanId);
        $hosts = [];

        foreach ($details['hosts'] ?? [] as $host) {
            if (! is_array($host) || ! isset($host['host_id'])) {
                continue;
            }

            $hostId = (int) $host['host_id'];
            $hostDetails = $client->hostDetails($nessusScanId, $hostId);
            $plugins = [];

            foreach ($hostDetails['vulnerabilities'] ?? [] as $vulnerability) {
                $pluginId = (int) ($vulnerability['plugin_id'] ?? 0);

                if ($pluginId > 0 && ! isset($plugins[$pluginId])) {
                    $plugins[$pluginId] = $client->hostPlugin($nessusScanId, $hostId, $pluginId);
                }
            }

            $hosts[] = ['summary' => $host, 'details' => $hostDetails, 'plugins' => $plugins];
        }

        return ['scan' => $details, 'hosts' => $hosts];
    }

    /**
     * Upserts hosts, assets and findings. Re-importing keeps each finding's
     * state (e.g. false positive) and first-found date, and removes findings
     * that are no longer in the scan.
     *
     * @param  array{scan: array<string, mixed>, hosts: list<array<string, mixed>>}  $bundle
     */
    private function persist(Scan $scan, array $bundle): void
    {
        $project = $scan->project;
        $info = $bundle['scan']['info'] ?? [];
        $started = $this->time($info['scan_start'] ?? null);
        $finished = $this->time($info['scan_end'] ?? null);
        $foundAt = $finished ?? $started ?? now();
        [$status, $notice] = $this->status((string) ($info['status'] ?? ''));

        $totals = array_fill_keys(Severity::keys(), 0);
        $hostIds = [];
        $instanceIds = [];

        foreach ($bundle['hosts'] as $host) {
            $facts = $host['details']['info'] ?? [];
            $label = $this->text($host['summary']['hostname'] ?? null);
            $ip = Str::limit($this->text($facts['host-ip'] ?? null) ?? $label ?? 'unknown', 45, '');
            $hostname = $this->text($facts['netbios-name'] ?? null) ?? ($label !== $ip ? $label : null);
            $fqdn = $this->text($facts['host-fqdn'] ?? null);
            $os = $this->text($facts['operating-system'] ?? null);

            $asset = $project->assets()->firstOrNew(['ip_address' => $ip]);
            $asset->fill(array_filter(['hostname' => $hostname, 'fqdn' => $fqdn, 'operating_system' => $os]))->save();

            $scanHost = $scan->hosts()->updateOrCreate(['ip_address' => $ip], [
                'asset_id' => $asset->id,
                'nessus_host_id' => (int) $host['summary']['host_id'],
                'hostname' => $hostname,
                'fqdn' => $fqdn,
                'operating_system' => $os,
                'status' => ($host['summary']['scanprogresscurrent'] ?? null) === ($host['summary']['scanprogresstotal'] ?? null)
                    ? 'completed' : 'partial',
            ]);
            $hostIds[] = $scanHost->id;
            $counts = array_fill_keys(Severity::keys(), 0);

            foreach ($host['plugins'] as $pluginId => $plugin) {
                $vulnerability = $this->vulnerability((int) $pluginId, $plugin);

                foreach ($this->findings($plugin, $vulnerability) as $finding) {
                    $instance = VulnerabilityInstance::query()->firstOrNew([
                        'scan_host_id' => $scanHost->id,
                        'vulnerability_id' => $vulnerability->id,
                        'port' => $finding['port'],
                        'protocol' => $finding['protocol'],
                    ]);
                    $instance->forceFill([
                        'project_id' => $project->id,
                        'scan_id' => $scan->id,
                        'scan_host_id' => $scanHost->id,
                    ]);
                    $instance->fill([
                        'asset_id' => $asset->id,
                        'service' => $finding['service'],
                        'severity' => $finding['severity'],
                        'cvss_score' => $vulnerability->cvss_score,
                        'plugin_output' => $finding['output'],
                        'first_found_at' => $instance->first_found_at ?? $foundAt,
                        'last_found_at' => $foundAt,
                    ])->save();

                    $instanceIds[] = $instance->id;
                    $counts[$finding['severity']->key()]++;
                }
            }

            $scanHost->update($this->countColumns($counts));

            foreach ($counts as $key => $count) {
                $totals[$key] += $count;
            }
        }

        $scan->vulnerabilityInstances()->whereNotIn('id', $instanceIds)->delete();
        $scan->hosts()->whereNotIn('id', $hostIds)->delete();

        $scan->forceFill([
            'name' => Str::limit($this->text($info['name'] ?? null) ?? $scan->name, 150, ''),
            'targets' => $this->text($info['targets'] ?? null) ?? $scan->targets,
            'status' => $status,
            'error_message' => $notice,
            'started_at' => $started,
            'finished_at' => $finished,
            'duration_seconds' => $started && $finished ? max(0, (int) $started->diffInSeconds($finished)) : null,
            'total_hosts' => (int) ($info['hostcount'] ?? count($bundle['hosts'])),
            'scanned_hosts' => count($bundle['hosts']),
            ...$this->countColumns($totals),
            'last_polled_at' => now(),
            'imported_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function countColumns(array $counts): array
    {
        $columns = ['total_findings' => array_sum($counts)];

        foreach ($counts as $key => $count) {
            $columns["{$key}_count"] = $count;
        }

        return $columns;
    }

    /**
     * Plugin catalogue entry, refreshed once per import.
     *
     * @param  array<string, mixed>  $plugin
     */
    private function vulnerability(int $pluginId, array $plugin): Vulnerability
    {
        if (isset($this->plugins[$pluginId])) {
            return $this->plugins[$pluginId];
        }

        $description = $plugin['info']['plugindescription'] ?? [];
        $attributes = $description['pluginattributes'] ?? [];
        $risk = $attributes['risk_information'] ?? [];

        [$score, $vector, $version] = match (true) {
            isset($risk['cvss3_base_score']) => [$risk['cvss3_base_score'], $risk['cvss3_vector'] ?? null, '3.0'],
            isset($risk['cvss4_base_score']) => [$risk['cvss4_base_score'], $risk['cvss4_vector'] ?? null, '4.0'],
            isset($risk['cvss_base_score']) => [$risk['cvss_base_score'], $risk['cvss_vector'] ?? null, '2.0'],
            default => [null, null, null],
        };

        $cves = collect($attributes['ref_information']['ref'] ?? [])
            ->filter(fn ($ref) => is_array($ref) && strtolower((string) ($ref['name'] ?? '')) === 'cve')
            ->flatMap(fn (array $ref) => Arr::wrap($ref['values']['value'] ?? []))
            ->map(fn ($cve) => trim((string) $cve))
            ->filter()
            ->unique()
            ->values();

        return $this->plugins[$pluginId] = Vulnerability::query()->updateOrCreate(['plugin_id' => $pluginId], [
            'name' => Str::limit($this->text($description['pluginname'] ?? $attributes['plugin_name'] ?? null) ?? "Plugin {$pluginId}", 500, ''),
            'family' => $this->text($description['pluginfamily'] ?? $attributes['plugin_information']['plugin_family'] ?? null),
            'severity' => Severity::tryFrom((int) ($description['severity'] ?? 0)) ?? Severity::Info,
            'synopsis' => $this->text($attributes['synopsis'] ?? null),
            'description' => $this->text($attributes['description'] ?? null),
            'solution' => $this->text($attributes['solution'] ?? null),
            'see_also' => $this->text(implode("\n", Arr::wrap($attributes['see_also'] ?? []))),
            'cve' => $cves->isEmpty() ? null : $cves->implode(', '),
            'cvss_score' => is_numeric($score) ? round((float) $score, 1) : null,
            'cvss_vector' => $vector !== null ? Str::limit((string) $vector, 255, '') : null,
            'cvss_version' => $version,
        ]);
    }

    /**
     * One finding per port; several outputs for the same port are combined.
     *
     * The severity comes from the plugin, not from outputs[].severity: the
     * latter is the CVSS v2 rating, while the plugin severity follows the
     * scan's severity base (CVSS v3 by default), as shown in the Nessus UI.
     *
     * @param  array<string, mixed>  $plugin
     * @return list<array{port: int, protocol: string, service: string|null, severity: Severity, output: string|null}>
     */
    private function findings(array $plugin, Vulnerability $vulnerability): array
    {
        $severity = $vulnerability->severity;
        $findings = [];

        foreach ($plugin['outputs'] ?? [] as $output) {
            $text = $this->text($output['plugin_output'] ?? null);

            foreach (array_keys($output['ports'] ?? []) ?: ['0 / tcp / '] as $portKey) {
                [$port, $protocol, $service] = $this->port((string) $portKey);
                $key = "{$port}/{$protocol}";

                if (! isset($findings[$key])) {
                    $findings[$key] = compact('port', 'protocol', 'service', 'severity') + ['output' => $text];
                } elseif ($text !== null && $text !== $findings[$key]['output']) {
                    $findings[$key]['output'] = trim(($findings[$key]['output'] ?? '')."\n\n".$text);
                }
            }
        }

        return $findings === []
            ? [['port' => 0, 'protocol' => 'tcp', 'service' => null, 'severity' => $severity, 'output' => null]]
            : array_values($findings);
    }

    /**
     * Parses Nessus port keys such as "443 / tcp / www" or "0 / tcp / ".
     *
     * @return array{int, string, string|null}
     */
    private function port(string $key): array
    {
        $parts = array_map('trim', explode('/', $key));

        return [
            min(65535, max(0, (int) ($parts[0] ?? 0))),
            Str::limit(strtolower($parts[1] ?? '') ?: 'tcp', 10, ''),
            ($parts[2] ?? '') !== '' ? Str::limit($parts[2], 50, '') : null,
        ];
    }

    /**
     * @return array{ScanStatus, string|null}
     */
    private function status(string $nessusStatus): array
    {
        return match ($nessusStatus) {
            'completed', 'imported' => [ScanStatus::Imported, null],
            'canceled', 'cancelled', 'stopped' => [ScanStatus::Cancelled, 'The scan was stopped in Nessus before it finished, so these results are partial.'],
            'aborted' => [ScanStatus::Failed, 'The scan was aborted in Nessus, so these results are partial.'],
            default => [ScanStatus::Running, sprintf(
                'The scan is still %s in Nessus, so these results are partial. Re-sync once it has finished.',
                $nessusStatus !== '' ? $nessusStatus : 'running',
            )],
        };
    }

    /**
     * Keeps the untouched Nessus responses on the private disk as evidence.
     * An identical re-sync is not stored twice.
     *
     * @param  array<string, mixed>  $bundle
     */
    private function storeRaw(Scan $scan, array $bundle): void
    {
        $json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $checksum = hash('sha256', $json);

        if ($scan->rawResults()->where('checksum', $checksum)->exists()) {
            return;
        }

        $now = now();
        $path = $scan->project->storagePath(
            'scans', $now->format('Y'), $now->format('m'), $now->format('d'), (string) $scan->id, 'raw',
            sprintf('nessus-%d-%s.json', $scan->nessus_scan_id, $now->format('Ymd-His')),
        );

        Storage::disk(config('nessus.disk'))->put($path, $json);

        $raw = new RawScanResult([
            'format' => 'json',
            'file_path' => $path,
            'checksum' => $checksum,
            'size_bytes' => strlen($json),
            'imported_at' => $now,
        ]);
        $raw->project_id = $scan->project_id;
        $raw->scan_id = $scan->id;
        $raw->save();
    }

    private function time(mixed $value): ?CarbonInterface
    {
        return is_numeric($value) && (int) $value > 0 ? Date::createFromTimestamp((int) $value) : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
