<?php

namespace App\Services;

use App\Enums\ProjectRole;
use App\Enums\ReportStatus;
use App\Enums\ScanStatus;
use App\Enums\Severity;
use App\Enums\VulnerabilityState;
use App\Http\Resources\ReportResource;
use App\Models\Project;
use App\Models\User;
use App\Models\VulnerabilityInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $creator, array $data): Project
    {
        return DB::transaction(function () use ($creator, $data) {
            $project = new Project($data);
            $project->created_by = $creator->id;
            $project->save();

            $project->members()->attach($creator, ['role' => ProjectRole::Manager->value]);

            if (array_key_exists('nessus_server_ids', $data)) {
                $project->nessusServers()->sync($data['nessus_server_ids'] ?? []);
            }

            $this->audit->log('project.created', $project, metadata: [
                'code' => $project->code,
                'name' => $project->name,
            ]);

            return $project;
        });
    }

    /**
     * The project code is immutable: storage paths and report numbers use it.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data) {
            $project->fill(collect($data)->except(['code', 'nessus_server_ids'])->all());
            $changed = array_keys($project->getDirty());
            $project->save();

            if (array_key_exists('nessus_server_ids', $data)) {
                $sync = $project->nessusServers()->sync($data['nessus_server_ids'] ?? []);
                if ($sync['attached'] !== [] || $sync['detached'] !== []) {
                    $changed[] = 'nessus_servers';
                }
            }

            $this->audit->log('project.updated', $project, metadata: ['changed' => $changed]);

            return $project;
        });
    }

    /**
     * Permanently deletes the project with its scans, findings, assets,
     * reports and files. Nothing is removed from Nessus. The caller must
     * type the project code, since this cannot be undone.
     */
    public function delete(Project $project, ?string $confirmation): void
    {
        if (strtoupper(trim((string) $confirmation)) !== $project->code) {
            throw ValidationException::withMessages([
                'confirm' => "Type the project code {$project->code} to confirm.",
            ]);
        }

        // A job still writing to the project would fail half-way; wait for it.
        $busySince = now()->subMinutes(15);
        $busy = $project->scans()->whereIn('status', [ScanStatus::Queued, ScanStatus::Importing])->where('updated_at', '>', $busySince)->exists()
            || $project->reports()->whereIn('status', [ReportStatus::Pending, ReportStatus::Generating])->where('updated_at', '>', $busySince)->exists();

        if ($busy) {
            throw ValidationException::withMessages([
                'confirm' => 'A scan import or report is still running for this project. Try again in a minute.',
            ]);
        }

        $counts = [
            'scans' => $project->scans()->count(),
            'findings' => $project->vulnerabilityInstances()->count(),
            'reports' => $project->reports()->withTrashed()->count(),
        ];

        DB::transaction(function () use ($project, $counts) {
            // Logged first: the row survives with project_id set to null.
            $this->audit->log('project.deleted', $project, metadata: [
                'code' => $project->code,
                'name' => $project->name,
                ...$counts,
            ]);

            // Scans and reports restrict deletes on purpose; everything below
            // them (hosts, findings, raw results) cascades in the database.
            $project->reports()->withTrashed()->forceDelete();
            $project->scans()->delete();
            $project->delete();
        });

        // Raw Nessus results and PDFs, only once the rows are gone.
        Storage::disk(config('nessus.disk'))->deleteDirectory($project->storagePath());
    }

    /**
     * Headline numbers for one project.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Project $project): array
    {
        $open = $project->vulnerabilityInstances()->where('state', VulnerabilityState::Open);

        return [
            'assets' => $project->assets()->count(),
            'scans' => $project->scans()->count(),
            'open_vulnerabilities' => (clone $open)->count(),
            'severity' => $this->severityCounts(clone $open),
            'recent_scans' => $project->scans()->with('nessusServer:id,name')->latest()->limit(10)->get()
                ->map(fn ($scan) => [
                    'id' => $scan->id,
                    'name' => $scan->name,
                    'status' => $scan->status->value,
                    'targets' => $scan->targets,
                    'nessus_server' => $scan->nessusServer?->name,
                    'total_findings' => $scan->total_findings,
                    'critical_count' => $scan->critical_count,
                    'high_count' => $scan->high_count,
                    'medium_count' => $scan->medium_count,
                    'low_count' => $scan->low_count,
                    'error_message' => $scan->error_message,
                    'imported_at' => $scan->imported_at?->toIso8601String(),
                    'finished_at' => $scan->finished_at?->toIso8601String(),
                    'created_at' => $scan->created_at?->toIso8601String(),
                ]),
            'top_hosts' => $this->topHosts($project),
            'recent_reports' => ReportResource::collection(
                $project->reports()->with(['scan:id,name', 'creator:id,name'])->latest('id')->limit(10)->get(),
            )->resolve(),
        ];
    }

    /**
     * Open findings per severity across the projects the user can see.
     *
     * @return array<string, int>
     */
    public function severityCountsFor(User $user): array
    {
        return $this->severityCounts(
            VulnerabilityInstance::query()
                ->where('state', VulnerabilityState::Open)
                ->whereIn('project_id', Project::query()->visibleTo($user)->select('id')),
        );
    }

    /**
     * @param  Builder<VulnerabilityInstance>|HasMany<VulnerabilityInstance, Project>  $query
     * @return array<string, int>
     */
    private function severityCounts($query): array
    {
        $counts = $query->toBase()
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $result = [];
        foreach (array_reverse(Severity::cases()) as $severity) {
            $result[strtolower($severity->name)] = (int) ($counts[$severity->value] ?? 0);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topHosts(Project $project): array
    {
        return $project->vulnerabilityInstances()
            ->where('state', VulnerabilityState::Open)
            ->whereNotNull('asset_id')
            ->with('asset:id,hostname,ip_address')
            ->select('asset_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when severity = ? then 1 else 0 end) as critical', [Severity::Critical->value])
            ->selectRaw('sum(case when severity = ? then 1 else 0 end) as high', [Severity::High->value])
            ->groupBy('asset_id')
            ->orderByDesc('critical')
            ->orderByDesc('high')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn (VulnerabilityInstance $row) => [
                'asset_id' => $row->asset_id,
                'hostname' => $row->asset?->hostname,
                'ip_address' => $row->asset?->ip_address,
                'total' => (int) $row->getAttribute('total'),
                'critical' => (int) $row->getAttribute('critical'),
                'high' => (int) $row->getAttribute('high'),
            ])
            ->values()
            ->all();
    }
}
