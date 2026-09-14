<?php

namespace App\Http\Resources;

use App\Models\NessusServer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a Nessus server. Deliberately lists every field: API keys
 * are never serialized, only whether they are configured.
 *
 * @mixin NessusServer
 */
class NessusServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'base_url' => $this->base_url,
            'verify_ssl' => $this->verify_ssl,
            'status' => $this->status->value,
            'server_version' => $this->server_version,
            'last_error' => $this->last_error,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_connected_at' => $this->last_connected_at?->toIso8601String(),
            'credentials_configured' => filled($this->getRawOriginal('access_key')) && filled($this->getRawOriginal('secret_key')),
            'projects_count' => $this->whenCounted('projects'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
