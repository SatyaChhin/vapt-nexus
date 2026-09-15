<?php

namespace App\Http\Requests;

use App\Enums\ProjectRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Create / update a user and their project access. On update, a blank
 * password keeps the current one.
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            ? $this->user()->can('update', $user)
            : $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user instanceof User ? $user->id : null)],
            'role' => ['required', Rule::enum(UserRole::class)],
            // Same minimum as the vapt:user command; production adds complexity rules.
            'password' => [$user instanceof User ? 'nullable' : 'required', 'string', 'min:12', Password::defaults(), 'confirmed'],
            'projects' => ['present', 'array'],
            'projects.*.id' => ['required', 'integer', 'distinct', 'exists:projects,id'],
            'projects.*.role' => ['required', Rule::enum(ProjectRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'projects.*.id' => 'project',
            'projects.*.role' => 'project role',
        ];
    }
}
