<?php

namespace App\Http\Requests;

use App\Models\NessusServer;
use App\Rules\AllowedNessusUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create / update a Nessus server. On update, blank API keys keep the stored ones.
 */
class NessusServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return $server instanceof NessusServer
            ? $this->user()->can('update', $server)
            : $this->user()->can('create', NessusServer::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'base_url' => is_string($this->base_url) ? rtrim(trim($this->base_url), '/') : $this->base_url,
            'access_key' => is_string($this->access_key) ? trim($this->access_key) : $this->access_key,
            'secret_key' => is_string($this->secret_key) ? trim($this->secret_key) : $this->secret_key,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $keyRequired = $this->route('server') instanceof NessusServer ? 'nullable' : 'required';

        // Nessus API keys are alphanumeric; the pattern also stops header injection.
        $key = [$keyRequired, 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9]+$/'];

        return [
            'name' => ['required', 'string', 'max:100'],
            'base_url' => ['required', 'string', 'max:255', 'url:https,http', new AllowedNessusUrl],
            'access_key' => $key,
            'secret_key' => $key,
            'verify_ssl' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'access_key.regex' => 'The access key may only contain letters and numbers.',
            'secret_key.regex' => 'The secret key may only contain letters and numbers.',
        ];
    }
}
