<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Enums\Severity;
use App\Enums\VulnerabilityState;
use App\Jobs\GenerateScanReport;
use App\Models\Project;
use App\Models\Report;
use App\Models\Scan;
use App\Models\ScanHost;
use App\Models\User;
use App\Models\VulnerabilityInstance;
use App\Services\AuditLogger;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds the PDF vulnerability report of one scan. Nessus Essentials cannot
 * export reports, so the report is rendered from the imported results.
 */
class ScanReportService
{
    /** Plugin output is cut to this many characters in the PDF. */
    private const OUTPUT_LIMIT = 2500;

    /** Report colours, matching the severity palette of the web UI. */
    public const COLORS = [
        'critical' => ['#991b1b', '#ffffff'],
        'high' => ['#ef4444', '#ffffff'],
        'medium' => ['#f97316', '#ffffff'],
        'low' => ['#facc15', '#422006'],
        'info' => ['#0ea5e9', '#ffffff'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Reserves the next report number (VULN-{CODE}-{YEAR}-{00001}) and queues
     * the PDF. A null user means the report was triggered automatically.
     */
    public function queue(Scan $scan, ?User $user = null): Report
    {
        $report = DB::transaction(function () use ($scan, $user) {
            // Serializes numbering per project.
            $project = Project::query()->lockForUpdate()->findOrFail($scan->project_id);
            $prefix = sprintf('VULN-%s-%s-', $project->code, now()->format('Y'));

            // Deleted reports count too: a number is never given out twice.
            $last = Report::withTrashed()
                ->where('report_number', 'like', $prefix.'%')
                ->orderByDesc('report_number')
                ->value('report_number');

            $report = new Report([
                'scan_id' => $scan->id,
                'report_number' => $prefix.str_pad((string) ($last ? (int) Str::afterLast($last, '-') + 1 : 1), 5, '0', STR_PAD_LEFT),
                'title' => Str::limit('Vulnerability Assessment Report: '.$scan->name, 255, ''),
                'type' => 'vulnerability',
                'status' => ReportStatus::Pending,
            ]);
            $report->project_id = $project->id;
            $report->nessus_run_uuid = $scan->nessus_run_uuid;
            $report->created_by = $user?->id;
            $report->save();

            return $report;
        });

        $this->audit->log('report.queued', $report, metadata: [
            'report_number' => $report->report_number,
            'scan_id' => $scan->id,
            'automatic' => $user === null,
        ], user: $user);

        GenerateScanReport::dispatch($report, $user);

        return $report->refresh();
    }

    /**
     * Whether the Nessus run currently imported for the scan already has a
     * report, finished or on its way.
     */
    public function hasReportForCurrentRun(Scan $scan): bool
    {
        return $scan->reports()
            ->where('status', '!=', ReportStatus::Failed)
            ->where('nessus_run_uuid', $scan->nessus_run_uuid)
            ->exists();
    }

    public function generate(Report $report, ?User $user = null): void
    {
        // Deleted while it waited on the queue.
        if ($report->trashed()) {
            return;
        }

        $scan = $report->scan;

        if ($scan === null) {
            $this->fail($report, $user);

            return;
        }

        $report->forceFill(['status' => ReportStatus::Generating])->save();

        $pdf = $this->render($report, $scan, $user);
        $path = $report->project->storagePath('reports', $report->created_at->format('Y'), $report->report_number.'.pdf');
        $disk = Storage::disk(config('nessus.disk'));

        $disk->put($path, $pdf);

        // Deleted while the PDF was being rendered: leave no file behind.
        if (Report::withTrashed()->whereKey($report->id)->value('deleted_at') !== null) {
            $disk->delete($path);

            return;
        }

        $report->forceFill([
            'status' => ReportStatus::Completed,
            'file_path' => $path,
            'generated_at' => now(),
        ])->save();

        $this->audit->log('report.generated', $report, metadata: [
            'report_number' => $report->report_number,
            'size_bytes' => strlen($pdf),
        ], user: $user);
    }

    /**
     * Removes the PDF and hides the report. The row stays (soft delete) so
     * its number cannot be reused and the audit trail still resolves.
     */
    public function delete(Report $report, User $user): void
    {
        if ($report->file_path !== null) {
            Storage::disk(config('nessus.disk'))->delete($report->file_path);
        }

        $report->forceFill(['file_path' => null])->save();
        $report->delete();

        $this->audit->log('report.deleted', $report, metadata: [
            'report_number' => $report->report_number,
            'scan_id' => $report->scan_id,
            'status' => $report->status->value,
        ], user: $user);
    }

    public function fail(Report $report, ?User $user = null): void
    {
        $report->forceFill(['status' => ReportStatus::Failed])->save();

        $this->audit->log('report.failed', $report, metadata: ['report_number' => $report->report_number], user: $user);
    }

    private function render(Report $report, Scan $scan, ?User $user): string
    {
        $fonts = storage_path('fonts');
        File::ensureDirectoryExists($fonts);

        $options = new Options;
        // Everything is embedded in the HTML: never fetch remote content and
        // never execute PHP, whatever text Nessus put in plugin output.
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(resource_path('views/reports'));
        $options->setFontDir($fonts);
        $options->setFontCache($fonts);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.scan', $this->data($report, $scan, $user))->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $grey = [0.42, 0.45, 0.5];
        $y = $canvas->get_height() - 30;
        $canvas->page_text(42, $y, $report->report_number.'  ·  Confidential', $font, 7.5, $grey);
        $canvas->page_text($canvas->get_width() - 100, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, $grey);

        return (string) $dompdf->output();
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Report $report, Scan $scan, ?User $user): array
    {
        $scan->loadMissing(['project', 'nessusServer']);

        // Findings triaged as false positives are left out of the report.
        $instances = $scan->vulnerabilityInstances()
            ->where('state', '!=', VulnerabilityState::FalsePositive)
            ->with(['vulnerability', 'scanHost:id,ip_address,hostname,fqdn'])
            ->get();

        $counts = $this->counts($instances);
        $findings = $this->findings($instances);
        $detailed = $findings
            ->reject(fn (array $finding) => $finding['severity'] === Severity::Info)
            ->values()
            ->map(fn (array $finding, int $index) => [...$finding, 'ref' => sprintf('F-%03d', $index + 1)]);

        $byHost = $instances->groupBy('scan_host_id');
        $hosts = $scan->hosts()->orderBy('ip_address')->get()
            ->map(fn (ScanHost $host) => [
                'ip_address' => $host->ip_address,
                'name' => $host->fqdn ?? $host->hostname,
                'operating_system' => $host->operating_system,
                'counts' => $this->counts($byHost->get($host->id, collect())),
            ])
            ->sortByDesc(fn (array $host) => [$host['counts']['critical'], $host['counts']['high'], $host['counts']['medium']])
            ->values();

        $overall = collect(array_reverse(Severity::cases()))
            ->first(fn (Severity $severity) => $severity !== Severity::Info && $counts[$severity->key()] > 0);

        return [
            'report' => $report,
            'project' => $scan->project,
            'scan' => $scan,
            'counts' => $counts,
            'total' => array_sum($counts),
            'overall' => $overall,
            'hosts' => $hosts,
            'detailed' => $detailed,
            'informational' => $findings->filter(fn (array $finding) => $finding['severity'] === Severity::Info)->values(),
            'generatedBy' => $user?->name ?? 'VAPT Nexus (automatically, when the scan finished)',
            'generatedAt' => now(),
            'colors' => self::COLORS,
        ];
    }

    /**
     * @param  Collection<int, VulnerabilityInstance>  $instances
     * @return array<string, int>
     */
    private function counts(Collection $instances): array
    {
        $counts = array_fill_keys(Severity::keys(), 0);

        foreach ($instances as $instance) {
            $counts[$instance->severity->key()]++;
        }

        return $counts;
    }

    /**
     * One entry per plugin, most severe first.
     *
     * @param  Collection<int, VulnerabilityInstance>  $instances
     * @return Collection<int, array<string, mixed>>
     */
    private function findings(Collection $instances): Collection
    {
        return $instances
            ->groupBy('vulnerability_id')
            ->map(function (Collection $group) {
                $vulnerability = $group->first()->vulnerability;

                return [
                    'vulnerability' => $vulnerability,
                    'severity' => Severity::from($group->max(fn (VulnerabilityInstance $i) => $i->severity->value)),
                    'cve' => array_values(array_filter(array_map('trim', explode(',', (string) $vulnerability->cve)))),
                    'see_also' => array_values(array_filter(array_map('trim', explode("\n", (string) $vulnerability->see_also)))),
                    'instances' => $group
                        ->sortBy([fn ($a, $b) => strnatcmp((string) $a->scanHost?->ip_address, (string) $b->scanHost?->ip_address), ['port', 'asc']])
                        ->map(fn (VulnerabilityInstance $instance) => [
                            'ip_address' => $instance->scanHost?->ip_address,
                            'port' => $instance->port,
                            'protocol' => $instance->protocol,
                            'service' => $instance->service,
                            'state' => $instance->state,
                            'output' => $instance->plugin_output !== null
                                ? Str::limit(trim($instance->plugin_output), self::OUTPUT_LIMIT, "\n[… output truncated, full text in VAPT Nexus]")
                                : null,
                        ])
                        ->values(),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['severity']->value, (float) $b['vulnerability']->cvss_score, $a['vulnerability']->name]
                <=> [$a['severity']->value, (float) $a['vulnerability']->cvss_score, $b['vulnerability']->name])
            ->values();
    }
}
