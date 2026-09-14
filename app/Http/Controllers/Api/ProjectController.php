<?php

namespace App\Http\Controllers\Api;

use App\Enums\VulnerabilityState;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Project endpoints. Every nested listing is queried through the bound
 * project after authorizing it, so results never cross project boundaries.
 */
class ProjectController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);

        return ProjectResource::collection(
            Project::query()
                ->visibleTo($request->user())
                ->withCount([
                    'scans',
                    'assets',
                    'vulnerabilityInstances as open_findings_count' => fn ($query) => $query->where('state', VulnerabilityState::Open),
                ])
                ->orderBy('name')
                ->paginate(self::PER_PAGE),
        );
    }

    public function store(ProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->user(), $request->validated());

        return (new ProjectResource($project->load('nessusServers')))->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project->load(['creator', 'nessusServers']));
    }

    public function update(ProjectRequest $request, Project $project): ProjectResource
    {
        return new ProjectResource($this->projects->update($project, $request->validated())->load('nessusServers'));
    }

    public function destroy(Project $project): Response
    {
        Gate::authorize('delete', $project);

        $this->projects->delete($project);

        return response()->noContent();
    }

    public function dashboard(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['data' => $this->projects->dashboard($project)]);
    }

    public function scans(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json($project->scans()->latest()->paginate(self::PER_PAGE));
    }

    public function assets(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json($project->assets()->orderBy('ip_address')->paginate(self::PER_PAGE));
    }

    public function vulnerabilities(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $validated = $request->validate([
            'state' => ['nullable', 'string', 'in:'.implode(',', array_column(VulnerabilityState::cases(), 'value'))],
            'severity' => ['nullable', 'integer', 'between:0,4'],
        ]);

        return response()->json(
            $project->vulnerabilityInstances()
                ->with(['vulnerability:id,plugin_id,name,cve,family', 'asset:id,hostname,ip_address'])
                ->when($validated['state'] ?? null, fn ($query, $state) => $query->where('state', $state))
                ->when(isset($validated['severity']), fn ($query) => $query->where('severity', $validated['severity']))
                ->orderByDesc('severity')
                ->paginate(self::PER_PAGE),
        );
    }

    public function reports(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(
            $project->reports()->latest()->paginate(self::PER_PAGE, ['id', 'project_id', 'scan_id', 'report_number', 'title', 'type', 'status', 'generated_at', 'created_at']),
        );
    }
}
