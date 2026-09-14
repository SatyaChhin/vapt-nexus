<?php

namespace Database\Factories;

use App\Models\RawScanResult;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawScanResult>
 */
class RawScanResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scan_id' => Scan::factory(),
            'project_id' => fn (array $attributes) => Scan::query()->findOrFail($attributes['scan_id'])->project_id,
            'format' => 'nessus',
            'file_path' => 'projects/TEST/scans/raw/'.fake()->uuid().'.nessus',
            'checksum' => hash('sha256', fake()->uuid()),
            'size_bytes' => 1024,
        ];
    }
}
