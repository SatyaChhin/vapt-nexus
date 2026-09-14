<?php

namespace Database\Factories;

use App\Enums\NessusServerStatus;
use App\Models\NessusServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NessusServer>
 */
class NessusServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Nessus '.fake()->unique()->word(),
            'base_url' => 'https://192.168.56.'.fake()->numberBetween(10, 250).':8834',
            'access_key' => bin2hex(random_bytes(32)),
            'secret_key' => bin2hex(random_bytes(32)),
            'verify_ssl' => false,
            'status' => NessusServerStatus::Unknown,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'status' => NessusServerStatus::Connected,
            'server_version' => '10.8.3',
            'last_checked_at' => now(),
            'last_connected_at' => now(),
        ]);
    }
}
