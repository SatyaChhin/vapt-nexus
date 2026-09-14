<?php

namespace Database\Factories;

use App\Models\Scan;
use App\Models\ScanHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanHost>
 */
class ScanHostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'asset_id' => null,
            'nessus_host_id' => fake()->numberBetween(1, 500),
            'ip_address' => '192.168.56.'.fake()->unique()->numberBetween(30, 250),
            'hostname' => fake()->domainWord(),
            'status' => 'completed',
        ];
    }
}
