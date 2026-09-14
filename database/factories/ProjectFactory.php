<?php

namespace Database\Factories;

use App\Enums\ProjectEnvironment;
use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->company(),
            'description' => fake()->sentence(),
            'environment' => ProjectEnvironment::Lab,
            'status' => ProjectStatus::Active,
            'created_by' => null,
        ];
    }

    /**
     * Add the user as a project member with the given role.
     */
    public function withMember(User $user, ProjectRole $role = ProjectRole::Analyst): static
    {
        return $this->afterCreating(fn (Project $project) => $project->members()->attach($user, ['role' => $role->value]));
    }
}
