<?php

namespace App\Services;

use App\Enums\ProjectRole;
use App\Enums\Severity;
use App\Enums\VulnerabilityState;
use App\Models\Project;
use App\Models\User;
use App\Models\VulnerabilityInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
     * Only projects without scan or report history can be deleted; anything
     * else must be archived so the assessment record is preserved.
     */
    public function delete(Project $project): void
    {
        if ($project->scans()->exists() || $project->reports()->exists()) {
            throw ValidationException::withMessages([
                'project' => 'This project has scans or reports. Archive it instead of deleting it.',
            ]);
        }

        $this->audit->log('project.deleted', $project, metadata: [
            'code' => $project->code,
            'name' => $project->name,
        ]);

        $project->delete();
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
                    'finished_at' => $scan->finished_at?->toIso8601String(),
                    'created_at' => $scan->created_at?->toIso8601String(),
                ]),
            'top_hosts' => $this->topHosts($project),
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
