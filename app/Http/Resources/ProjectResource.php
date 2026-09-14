<?php

namespace App\Http\Resources;

use App\Models\NessusServer;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'environment' => $this->environment->value,
            'status' => $this->status->value,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->only('id', 'name')),
            'nessus_servers' => $this->whenLoaded('nessusServers', fn () => $this->nessusServers->map(
                fn (NessusServer $server) => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'status' => $server->status->value,
                ],
            )),
            'scans_count' => $this->whenCounted('scans'),
            'assets_count' => $this->whenCounted('assets'),
            'open_findings_count' => $this->whenCounted('open_findings'),
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
