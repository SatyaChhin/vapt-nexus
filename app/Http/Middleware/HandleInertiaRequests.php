<?php

namespace App\Http\Middleware;

use App\Enums\ProjectStatus;
use App\Models\NessusServer;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'can' => [
                    'manageNessusServers' => (bool) $user?->can('viewAny', NessusServer::class),
                    'createProjects' => (bool) $user?->can('create', Project::class),
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Sidebar sub-menu under "Projects": only projects the user may see.
            // A closure, so partial reloads (polling) skip the query.
            'sidebarProjects' => fn () => $user === null ? [] : Project::query()
                ->visibleTo($user)
                ->where('status', '!=', ProjectStatus::Archived)
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => $project->only('id', 'code', 'name'))
                ->all(),
        ];
    }
}
