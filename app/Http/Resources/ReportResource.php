<?php

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'scan_id' => $this->scan_id,
            'scan_name' => $this->whenLoaded('scan', fn () => $this->scan?->name),
            'report_number' => $this->report_number,
            'title' => $this->title,
            'status' => $this->status->value,
            // Null creator: generated automatically when the scan finished.
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
