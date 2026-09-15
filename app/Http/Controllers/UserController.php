<?php

namespace App\Http\Controllers;

use App\Enums\ProjectRole;
use App\Http\Requests\UserRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts and their project access. Public registration is disabled, so
 * this page (and the vapt:user command) is how people get in.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        return Inertia::render('Users/Index', [
            'users' => User::query()
                ->withCount('projects')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'projects_count' => $user->projects_count,
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                    'disabled_at' => $user->disabled_at?->toIso8601String(),
                    'is_self' => $user->is($request->user()),
                    'created_at' => $user->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Users/Create', $this->formOptions());
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User :name created.', ['name' => $user->name])]);

        return to_route('users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'disabled_at' => $user->disabled_at?->toIso8601String(),
                'is_self' => $user->is($request->user()),
                'projects' => $user->projects()->get(['projects.id'])
                    ->map(fn (Project $project) => ['id' => $project->id, 'role' => $project->pivot?->getAttribute('role')])
                    ->values(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User :name updated.', ['name' => $user->name])]);

        return to_route('users.index');
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $disabled = $request->validate(['disabled' => ['required', 'boolean']])['disabled'];
        $this->users->setDisabled($user, (bool) $disabled, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $disabled
            ? __(':name can no longer sign in.', ['name' => $user->name])
            : __(':name can sign in again.', ['name' => $user->name])]);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $this->users->delete($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User :name deleted.', ['name' => $user->name])]);

        return to_route('users.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name', 'status'])
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'status' => $project->status->value,
                ]),
            'projectRoles' => array_map(fn (ProjectRole $role) => $role->value, ProjectRole::cases()),
        ];
    }
}
