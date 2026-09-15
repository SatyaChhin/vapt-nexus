<?php

namespace App\Policies;

use App\Models\User;

/**
 * Accounts and project access are managed by administrators only. Rules
 * that protect the admin's own account (no self-demotion, no disabling the
 * last admin) live in UserService, where they can explain themselves.
 */
class UserPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $model): bool
    {
        return false;
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }
}
