<?php

namespace Database\Seeders;

use App\Enums\ProjectEnvironment;
use App\Enums\ProjectRole;
use App\Enums\UserRole;
use App\Models\NessusServer;
use App\Models\Project;
use App\Models\User;
use App\Services\Nessus\NessusUrlGuard;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Idempotent: safe to run again, it never duplicates or overwrites records.
     */
    public function run(): void
    {
        $admin = $this->seedAdmin();
        $server = $this->seedNessusServer();

        $projects = [
            ['code' => 'HC', 'name' => 'Healthcare', 'description' => 'Healthcare platform assessment.'],
            ['code' => 'SS', 'name' => 'SecretShare', 'description' => 'SecretShare application assessment.'],
            ['code' => 'OCR', 'name' => 'Medical OCR', 'description' => 'Medical OCR service assessment.'],
            ['code' => 'POS', 'name' => 'KHMER POS', 'description' => 'KHMER POS system assessment.'],
        ];

        foreach ($projects as $attributes) {
            $project = Project::query()->firstOrNew(['code' => $attributes['code']]);

            if (! $project->exists) {
                $project->fill([...$attributes, 'environment' => ProjectEnvironment::Lab]);
                $project->created_by = $admin->id;
                $project->save();
                $project->members()->attach($admin, ['role' => ProjectRole::Manager->value]);
            }

            if ($server !== null) {
                $project->nessusServers()->syncWithoutDetaching([$server->id]);
            }
        }
    }

    private function seedAdmin(): User
    {
        $email = (string) env('VAPT_ADMIN_EMAIL', 'admin@vapt.local');

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }

        $password = (string) env('VAPT_ADMIN_PASSWORD', '');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(20);
        }

        $admin = new User([
            'name' => (string) env('VAPT_ADMIN_NAME', 'VAPT Administrator'),
            'email' => $email,
            'password' => $password,
        ]);
        $admin->role = UserRole::Admin;
        $admin->email_verified_at = now();
        $admin->save();

        $this->command?->info("Admin user: {$email}");
        if ($generated) {
            $this->command?->warn("Generated password (shown once, change it after login): {$password}");
        }

        return $admin;
    }

    /**
     * Registers NESSUS_URL from .env when API keys are also provided there.
     * Normally servers are added in the UI instead.
     */
    private function seedNessusServer(): ?NessusServer
    {
        $config = config('nessus.bootstrap');

        if (blank($config['url']) || blank($config['access_key']) || blank($config['secret_key'])) {
            return NessusServer::query()->first();
        }

        if (($error = NessusUrlGuard::check($config['url'])) !== null) {
            $this->command?->error("NESSUS_URL skipped: {$error}");

            return null;
        }

        return NessusServer::query()->firstOrCreate(
            ['base_url' => rtrim($config['url'], '/')],
            [
                'name' => $config['name'],
                'access_key' => $config['access_key'],
                'secret_key' => $config['secret_key'],
                'verify_ssl' => $config['verify_ssl'],
            ],
        );
    }
}
