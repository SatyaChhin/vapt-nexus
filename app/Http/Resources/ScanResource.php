<?php

namespace App\Http\Resources;

use App\Enums\Severity;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Scan
 */
class ScanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $severity = [];
        foreach (Severity::keys() as $key) {
            $severity[$key] = $this->{"{$key}_count"};
        }

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'targets' => $this->targets,
            'status' => $this->status->value,
            'nessus_server' => $this->whenLoaded('nessusServer', fn () => $this->nessusServer?->only('id', 'name')),
            'nessus_scan_id' => $this->nessus_scan_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'total_hosts' => $this->total_hosts,
            'scanned_hosts' => $this->scanned_hosts,
            'total_findings' => $this->total_findings,
            'severity' => $severity,
            'error_message' => $this->error_message,
            'imported_at' => $this->imported_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
