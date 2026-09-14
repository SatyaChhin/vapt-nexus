<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'scan_id' => null,
            'report_number' => 'VULN-TEST-'.now()->year.'-'.fake()->unique()->numerify('#####'),
            'title' => 'Vulnerability Assessment Report',
            'type' => 'vulnerability',
            'status' => ReportStatus::Pending,
        ];
    }
}
