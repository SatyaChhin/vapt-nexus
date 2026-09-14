<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;

/**
 * Project-level access control. Everything that belongs to a project (scans,
 * findings, raw results, reports) is authorized through the project, so a
 * user can never reach another project's data by guessing IDs.
 */
class ProjectPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->roleIn($project) !== null;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Project $project): bool
    {
        return $user->roleIn($project) === ProjectRole::Manager;
    }

    /**
     * Create, launch and import scans (used from Phase 3).
     */
    public function runScans(User $user, Project $project): bool
    {
        return in_array($user->roleIn($project), [ProjectRole::Manager, ProjectRole::Analyst], true);
    }

    /**
     * Delete generated reports. Reports may already be with a client, so
     * this is limited to managers (and admins).
     */
    public function deleteReports(User $user, Project $project): bool
    {
        return $user->roleIn($project) === ProjectRole::Manager;
    }

    public function delete(User $user, Project $project): bool
    {
        return false;
    }
}
