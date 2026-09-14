<?php

namespace App\Http\Controllers;

use App\Enums\NessusServerStatus;
use App\Enums\VulnerabilityState;
use App\Http\Resources\ProjectResource;
use App\Models\NessusServer;
use App\Models\Project;
use App\Models\Scan;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ProjectService $projects): Response
    {
        $user = $request->user();
        $visible = Project::query()->visibleTo($user);

        $servers = $user->can('viewAny', NessusServer::class)
            ? NessusServer::query()->orderBy('name')->get(['id', 'name', 'status', 'last_checked_at'])
                ->map(fn (NessusServer $server) => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'status' => $server->status->value,
                    'last_checked_at' => $server->last_checked_at?->toIso8601String(),
                ])
            : null;

        return Inertia::render('Dashboard', [
            'stats' => [
                'projects' => (clone $visible)->count(),
                'scans' => Scan::query()->whereIn('project_id', (clone $visible)->select('id'))->count(),
                'servers_total' => $servers?->count(),
                'servers_connected' => $servers?->where('status', NessusServerStatus::Connected->value)->count(),
                'severity' => $projects->severityCountsFor($user),
            ],
            'projects' => ProjectResource::collection(
                (clone $visible)
                    ->withCount([
                        'scans',
                        'assets',
                        'vulnerabilityInstances as open_findings_count' => fn ($query) => $query->where('state', VulnerabilityState::Open),
                    ])
                    ->orderBy('name')
                    ->limit(8)
                    ->get(),
            )->resolve(),
            'servers' => $servers,
        ]);
    }
}
