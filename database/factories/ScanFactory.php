<?php

namespace Database\Factories;

use App\Enums\ScanStatus;
use App\Models\Project;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scan>
 */
class ScanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'nessus_server_id' => null,
            'name' => 'Scan '.fake()->unique()->numerify('###'),
            'description' => null,
            'targets' => '192.168.56.30',
            'status' => ScanStatus::Created,
        ];
    }
}
