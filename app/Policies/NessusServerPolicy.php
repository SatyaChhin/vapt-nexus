<?php

namespace App\Policies;

use App\Models\NessusServer;
use App\Models\User;

/**
 * Nessus servers hold scanner credentials, so only administrators manage them.
 */
class NessusServerPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, NessusServer $server): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, NessusServer $server): bool
    {
        return false;
    }

    public function delete(User $user, NessusServer $server): bool
    {
        return false;
    }

    public function testConnection(User $user, NessusServer $server): bool
    {
        return false;
    }
}
