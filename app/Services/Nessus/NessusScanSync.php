<?php

namespace App\Services\Nessus;

use App\Enums\ProjectStatus;
use App\Enums\ScanStatus;
use App\Models\NessusServer;
use App\Models\Project;
use App\Models\Scan;
use App\Services\Nessus\Exceptions\NessusException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the app in step with Nessus; the scheduler runs it every minute.
 *
 * - A new scan whose name contains a project code as a separate word
 *   (VA_POS, POS-web, "pos scan" → POS) is imported into that project.
 * - When a Nessus run finishes, the scan is imported again. The importer
 *   then generates the report for that run.
 *
 * Only GET /scans is called per server per minute; hosts and plugins are
 * fetched only when something has to be imported.
 */
class NessusScanSync
{
    /** Nessus statuses after which a run's results no longer change. */
    private const FINISHED = ['completed', 'imported', 'canceled', 'cancelled', 'stopped', 'aborted'];

    /** How long a failed or stuck import waits before it is tried again. */
    private const RETRY_AFTER_MINUTES = 15;

    public function __construct(private readonly NessusScanImporter $importer) {}

    /**
     * @return array{imported: list<string>, synced: list<string>, running: list<string>, skipped: list<string>, errors: list<string>}
     */
    public function run(): array
    {
        $summary = ['imported' => [], 'synced' => [], 'running' => [], 'skipped' => [], 'errors' => []];

        foreach (NessusServer::query()->with('projects')->orderBy('id')->get() as $server) {
            try {
                $response = NessusClient::forServer($server)->scans();
            } catch (NessusException $e) {
                $summary['errors'][] = "{$server->name}: {$e->getMessage()}";

                continue;
            }

            $folders = collect($response['folders'] ?? [])->keyBy('id');
            $known = Scan::query()
                ->where('nessus_server_id', $server->id)
                ->whereNotNull('nessus_scan_id')
                ->get()
                ->keyBy('nessus_scan_id');
            $projects = $server->projects->reject(fn (Project $project) => $project->status === ProjectStatus::Archived);

            foreach ($response['scans'] ?? [] as $item) {
                if (! is_array($item) || ! isset($item['id'])) {
                    continue;
                }

                $label = sprintf('%s (#%d)', $item['name'] ?? 'Scan', $item['id']);
                $scan = $known->get((int) $item['id']);

                try {
                    $scan === null
                        ? $this->discover($server, $item, $folders, $projects, $label, $summary)
                        : $this->follow($scan, $item, $label, $summary);
                } catch (ValidationException $e) {
                    $summary['skipped'][] = $label.': '.collect($e->errors())->flatten()->first();
                } catch (NessusException $e) {
                    $summary['errors'][] = $label.': '.$e->getMessage();
                }
            }
        }

        return $summary;
    }

    /**
     * Projects whose code is a separate word of the scan name. "VA_POS" and
     * "pos-scan" match POS; "POSTGRES" does not.
     *
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, Project>
     */
    public function matchProjects(string $name, Collection $projects): Collection
    {
        $words = array_filter(preg_split('/[^A-Z0-9]+/', strtoupper($name)) ?: []);

        return $projects
            ->filter(fn (Project $project) => in_array(strtoupper($project->code), $words, true))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<array-key, mixed>  $folders
     * @param  Collection<int, Project>  $projects
     * @param  array<string, list<string>>  $summary
     */
    private function discover(NessusServer $server, array $item, Collection $folders, Collection $projects, string $label, array &$summary): void
    {
        $inTrash = ($folders->get($item['folder_id'] ?? null)['type'] ?? null) === 'trash';

        if ($inTrash || ($item['status'] ?? null) === 'empty') {
            return;
        }

        $matches = $this->matchProjects((string) ($item['name'] ?? ''), $projects);

        if ($matches->count() > 1) {
            $summary['skipped'][] = "{$label}: the name matches several projects ({$matches->pluck('code')->implode(', ')}). Import it by hand.";

            return;
        }

        if ($matches->isEmpty()) {
            return;
        }

        $this->importer->queue($matches->first(), $server, (int) $item['id']);
        $summary['imported'][] = "{$label} → {$matches->first()->code}";
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, list<string>>  $summary
     */
    private function follow(Scan $scan, array $item, string $label, array &$summary): void
    {
        $recent = $scan->updated_at?->gt(now()->subMinutes(self::RETRY_AFTER_MINUTES)) ?? false;
        $status = (string) ($item['status'] ?? '');
        $uuid = isset($item['uuid']) ? (string) $item['uuid'] : null;

        // Already on the queue, or failed a moment ago: leave it alone for now.
        if ($recent && in_array($scan->status, [ScanStatus::Queued, ScanStatus::Importing, ScanStatus::Failed], true)) {
            return;
        }

        if ($status === 'empty') {
            return;
        }

        if (! in_array($status, self::FINISHED, true)) {
            if ($scan->status !== ScanStatus::Running) {
                $scan->forceFill([
                    'status' => ScanStatus::Running,
                    'error_message' => 'A new run is in progress in Nessus. The results shown are from the previous run and are updated when it finishes.',
                    'last_polled_at' => now(),
                ])->save();
                $summary['running'][] = $label;
            }

            return;
        }

        $newRun = $uuid !== null && $uuid !== $scan->nessus_run_uuid;

        if ($newRun || $scan->status === ScanStatus::Running) {
            $this->importer->resync($scan);
            $summary['synced'][] = $label;
        }
    }
}
