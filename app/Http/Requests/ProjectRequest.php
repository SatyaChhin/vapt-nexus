<?php

namespace App\Http\Requests;

use App\Enums\ProjectEnvironment;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create / update a project. The code is set once on create (it is used in
 * storage paths and report numbers) and Nessus server assignment is admin-only.
 */
class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            ? $this->user()->can('update', $project)
            : $this->user()->can('create', Project::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $creating = ! $this->route('project') instanceof Project;

        return [
            'code' => $creating
                ? ['required', 'string', 'regex:/^[A-Z0-9]{2,10}$/', Rule::unique('projects', 'code')]
                : ['prohibited'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'environment' => ['required', Rule::enum(ProjectEnvironment::class)],
            'status' => [$creating ? 'sometimes' : 'required', Rule::enum(ProjectStatus::class)],
            'nessus_server_ids' => [Rule::prohibitedIf(! $this->user()->isAdmin()), 'array'],
            'nessus_server_ids.*' => ['integer', 'distinct', Rule::exists('nessus_servers', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code must be 2-10 letters or digits, e.g. HC or POS.',
            'code.prohibited' => 'The project code cannot be changed after creation.',
            'nessus_server_ids.prohibited' => 'Only administrators can assign Nessus servers.',
        ];
    }
}
