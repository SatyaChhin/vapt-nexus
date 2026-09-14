<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'hostname' => fake()->domainWord(),
            'fqdn' => null,
            'ip_address' => '192.168.56.'.fake()->unique()->numberBetween(30, 250),
            'operating_system' => 'Linux',
            'asset_type' => 'host',
            'status' => 'active',
        ];
    }
}
