<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes audit_logs rows. Metadata keys that look like secrets are redacted
 * before storage, so callers cannot leak credentials by accident.
 */
class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'secret', 'access_key', 'token', 'api_key', 'apikey', 'authorization', 'cookie'];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        ?Model $resource = null,
        ?Project $project = null,
        array $metadata = [],
        ?User $user = null,
    ): AuditLog {
        $request = app()->bound('request') ? request() : null;

        $projectId = $project?->getKey()
            ?? ($resource instanceof Project ? $resource->getKey() : $resource?->getAttribute('project_id'));

        return AuditLog::create([
            'user_id' => ($user ?? auth()->user())?->getAuthIdentifier(),
            'project_id' => $projectId,
            'action' => $action,
            'resource' => $resource ? Str::snake(class_basename($resource)) : null,
            'resource_id' => $resource?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? Str::limit($request->userAgent(), 500, '') : null,
            'metadata' => $metadata === [] ? null : $this->redact($metadata),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && Str::contains(strtolower($key), self::SENSITIVE_KEYS)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
