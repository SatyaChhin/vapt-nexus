<?php

namespace App\Http\Controllers;

use App\Enums\Severity;
use App\Enums\VulnerabilityState;
use App\Models\Project;
use App\Models\VulnerabilityInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every finding across the projects the user can see, one row per host and
 * port. Filters arrive as query-string values; anything invalid is ignored.
 */
class FindingController extends Controller
{
    private const PER_PAGE = 50;

    /** Informational findings would bury the rest, so they are opt-in. */
    private const DEFAULT_SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $severities = collect(Severity::cases())->keyBy(fn (Severity $severity) => $severity->key());

        $query = VulnerabilityInstance::query()
            ->whereIn('project_id', Project::query()->visibleTo($user)->select('id'));
        $this->applyFilters($query, $filters);

        // Counts ignore the severity filter so every chip shows what it would add.
        $counts = (clone $query)->toBase()
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $findings = $query
            ->whereIn('severity', array_map(fn (string $key) => $severities[$key]->value, $filters['severity']))
            ->with([
                'vulnerability:id,plugin_id,name,cve,family',
                'scanHost:id,ip_address,hostname',
                'project:id,code,name',
            ])
            ->orderByDesc('severity')
            ->orderByDesc('cvss_score')
            ->orderByDesc('last_found_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (VulnerabilityInstance $finding) => $this->row($finding));

        return Inertia::render('Findings/Index', [
            'findings' => $findings,
            'counts' => $severities->map(fn (Severity $severity) => (int) ($counts[$severity->value] ?? 0)),
            'filters' => $filters,
            'projects' => Project::query()->visibleTo($user)->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => $project->only('id', 'code', 'name')),
            'states' => array_map(fn (VulnerabilityState $state) => $state->value, VulnerabilityState::cases()),
        ]);
    }

    /**
     * @return array{q: string, host: string, project: int|null, state: string, severity: list<string>}
     */
    private function filters(Request $request): array
    {
        $severity = array_values(array_intersect(
            Severity::keys(),
            explode(',', strtolower((string) $request->query('severity', ''))),
        ));
        $state = (string) $request->query('state', VulnerabilityState::Open->value);
        $project = $request->query('project');

        return [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 200),
            'host' => mb_substr(trim((string) $request->query('host', '')), 0, 255),
            'project' => is_numeric($project) ? (int) $project : null,
            'state' => $state === 'all' || VulnerabilityState::tryFrom($state) ? $state : VulnerabilityState::Open->value,
            'severity' => $severity === [] ? self::DEFAULT_SEVERITIES : $severity,
        ];
    }

    /**
     * Everything except severity, which the caller applies after counting.
     *
     * @param  Builder<VulnerabilityInstance>  $query
     * @param  array{q: string, host: string, project: int|null, state: string, severity: list<string>}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['project'], fn (Builder $q, int $id) => $q->where('project_id', $id))
            ->when($filters['state'] !== 'all', fn (Builder $q) => $q->where('state', $filters['state']))
            // A % or _ typed into the box only widens the match; values are bound.
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $like = "%{$filters['q']}%";

                $q->whereHas('vulnerability', fn (Builder $v) => $v
                    ->where('name', 'like', $like)
                    ->orWhere('cve', 'like', $like)
                    ->when(ctype_digit($filters['q']), fn (Builder $v) => $v->orWhere('plugin_id', (int) $filters['q'])));
            })
            ->when($filters['host'] !== '', function (Builder $q) use ($filters) {
                $like = "%{$filters['host']}%";

                $q->whereHas('scanHost', fn (Builder $h) => $h
                    ->where('ip_address', 'like', $like)
                    ->orWhere('hostname', 'like', $like)
                    ->orWhere('fqdn', 'like', $like));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(VulnerabilityInstance $finding): array
    {
        $cves = array_values(array_filter(array_map('trim', explode(',', (string) $finding->vulnerability?->cve))));

        return [
            'id' => $finding->id,
            'project' => $finding->project?->only('id', 'code', 'name'),
            'scan_id' => $finding->scan_id,
            'plugin_id' => $finding->vulnerability?->plugin_id,
            'name' => $finding->vulnerability?->name,
            'family' => $finding->vulnerability?->family,
            'cve' => $cves[0] ?? null,
            'cve_count' => count($cves),
            'severity' => $finding->severity->key(),
            'cvss_score' => $finding->cvss_score !== null ? (float) $finding->cvss_score : null,
            'ip_address' => $finding->scanHost?->ip_address,
            'hostname' => $finding->scanHost?->hostname,
            'port' => $finding->port,
            'protocol' => $finding->protocol,
            'service' => $finding->service,
            'state' => $finding->state->value,
            'first_found_at' => $finding->first_found_at?->toIso8601String(),
            'last_found_at' => $finding->last_found_at?->toIso8601String(),
        ];
    }
}
