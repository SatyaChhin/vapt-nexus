<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Account management from the Users page. Every change is audited, and an
 * admin can never lock everyone out: they cannot demote, disable or delete
 * themselves, or the last active admin.
 */
class UserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->role = UserRole::from($data['role']);
            // Accounts are created by an admin, so the address is trusted.
            $user->email_verified_at = now();
            $user->save();

            $access = $this->syncProjects($user, $data['projects'] ?? []);

            $this->audit->log('user.created', $user, metadata: [
                'email' => $user->email,
                'role' => $user->role->value,
                'via' => 'web',
                'projects' => $access['attached'],
            ]);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $role = UserRole::from($data['role']);

        if ($user->isAdmin() && $role !== UserRole::Admin) {
            $this->guardAdminRemoval($user, $actor, 'role', 'remove the admin role from');
        }

        return DB::transaction(function () use ($user, $data, $role) {
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            $user->role = $role;

            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }

            $changed = array_keys($user->getDirty());

            if ($user->isDirty('email')) {
                $user->email_verified_at = now();
            }

            $user->save();

            if (in_array('password', $changed, true)) {
                $this->signOutEverywhere($user);
            }

            $access = $this->syncProjects($user, $data['projects'] ?? []);
            if ($access['attached'] !== [] || $access['detached'] !== [] || $access['updated'] !== []) {
                $changed[] = 'projects';
            }

            $this->audit->log('user.updated', $user, metadata: ['changed' => $changed, 'projects' => $access]);

            return $user;
        });
    }

    public function setDisabled(User $user, bool $disabled, User $actor): void
    {
        if ($disabled === $user->isDisabled()) {
            return;
        }

        if ($disabled) {
            $this->guardAdminRemoval($user, $actor, 'user', 'disable');
        }

        $user->forceFill(['disabled_at' => $disabled ? now() : null])->save();

        if ($disabled) {
            $this->signOutEverywhere($user);
        }

        $this->audit->log($disabled ? 'user.disabled' : 'user.enabled', $user, metadata: ['email' => $user->email]);
    }

    /**
     * Their projects, scans and reports stay; audit rows keep the event but
     * lose the link to the user, so disabling is usually the better choice.
     */
    public function delete(User $user, User $actor): void
    {
        $this->guardAdminRemoval($user, $actor, 'user', 'delete');

        DB::transaction(function () use ($user) {
            $this->audit->log('user.deleted', $user, metadata: [
                'email' => $user->email,
                'role' => $user->role->value,
            ]);

            $user->delete();
        });
    }

    /**
     * @param  list<array{id: int|string, role: string}>  $projects
     * @return array{attached: list<int>, detached: list<int>, updated: list<int>}
     */
    private function syncProjects(User $user, array $projects): array
    {
        $roles = [];
        foreach ($projects as $project) {
            $roles[(int) $project['id']] = ['role' => $project['role']];
        }

        $result = $user->projects()->sync($roles);

        return [
            'attached' => array_values(array_map('intval', $result['attached'])),
            'detached' => array_values(array_map('intval', $result['detached'])),
            'updated' => array_values(array_map('intval', $result['updated'])),
        ];
    }

    /**
     * Stops the actor locking themselves out, and keeps one active admin.
     */
    private function guardAdminRemoval(User $user, User $actor, string $field, string $verb): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages([$field => "You can't {$verb} your own account."]);
        }

        $otherActiveAdmins = User::query()
            ->where('role', UserRole::Admin)
            ->whereNull('disabled_at')
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($user->isAdmin() && ! $user->isDisabled() && ! $otherActiveAdmins) {
            throw ValidationException::withMessages([$field => "You can't {$verb} the last active admin."]);
        }
    }

    /**
     * Ends the user's sessions (except the one making this request) and their
     * "remember me" cookies. With other session drivers, a disabled user is
     * still stopped by the EnsureUserIsActive middleware.
     */
    private function signOutEverywhere(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            $current = request()->hasSession() ? request()->session()->getId() : null;

            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($current, fn ($query, string $id) => $query->where('id', '!=', $id))
                ->delete();
        }
    }
}
