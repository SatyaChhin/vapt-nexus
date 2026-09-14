<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Public registration is disabled; accounts are created from the CLI.
 */
#[Signature('vapt:user {email? : Email address} {--name= : Display name} {--admin : Grant the administrator role}')]
#[Description('Create a VAPT Nexus user account')]
class CreateUserCommand extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $email = (string) ($this->argument('email') ?: text('Email', required: true));
        $name = (string) ($this->option('name') ?: text('Name', default: 'VAPT User', required: true));
        $plain = password('Password (min. 12 characters)', required: true);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $plain],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $plain]);
        $user->role = $this->option('admin') ? UserRole::Admin : UserRole::Member;
        $user->email_verified_at = now();
        $user->save();

        $audit->log('user.created', $user, metadata: ['email' => $email, 'role' => $user->role->value, 'via' => 'cli']);

        $this->info("Created {$user->role->value} user {$email}.");

        return self::SUCCESS;
    }
}
