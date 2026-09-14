<?php

namespace App\Http\Controllers;

use App\Enums\VulnerabilityState;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\NessusServer;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $projects = Project::query()
            ->visibleTo($request->user())
            ->withCount([
                'scans',
                'assets',
                'vulnerabilityInstances as open_findings_count' => fn ($query) => $query->where('state', VulnerabilityState::Open),
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('Projects/Index', [
            'projects' => ProjectResource::collection($projects)->resolve(),
            'can' => ['create' => $request->user()->can('create', Project::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('Projects/Create', [
            'nessusServers' => $this->serverOptions($request),
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = $this->projects->create($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project :code created.', ['code' => $project->code])]);

        return to_route('projects.show', $project);
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Show', [
            'project' => (new ProjectResource($project->load(['creator', 'nessusServers'])))->resolve(),
            'dashboard' => $this->projects->dashboard($project),
            // What a delete would remove, shown in the confirmation dialog.
            'deletion' => $request->user()->can('delete', $project) ? [
                'scans' => $project->scans()->count(),
                'findings' => $project->vulnerabilityInstances()->count(),
                'reports' => $project->reports()->count(),
            ] : null,
            'can' => [
                'importScans' => $request->user()->can('runScans', $project),
                'generateReports' => $request->user()->can('runScans', $project),
                'deleteReports' => $request->user()->can('deleteReports', $project),
            ],
        ]);
    }

    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('Projects/Edit', [
            'project' => (new ProjectResource($project->load('nessusServers')))->resolve(),
            'nessusServers' => $this->serverOptions($request),
        ]);
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->projects->update($project, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.show', $project);
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $this->projects->delete($project, $request->string('confirm')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project :name deleted.', ['name' => $project->name])]);

        return to_route('projects.index');
    }

    /**
     * Servers an admin can assign; empty for everyone else.
     *
     * @return list<array{id: int, name: string, status: string}>
     */
    private function serverOptions(Request $request): array
    {
        if (! $request->user()->can('viewAny', NessusServer::class)) {
            return [];
        }

        return NessusServer::query()->orderBy('name')->get(['id', 'name', 'status'])
            ->map(fn (NessusServer $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status->value,
            ])
            ->all();
    }
}
