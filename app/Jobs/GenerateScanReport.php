<?php

namespace App\Jobs;

use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ScanReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Renders a scan's PDF report on the queue.
 */
class GenerateScanReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Report $report, public ?User $user = null) {}

    public function handle(ScanReportService $reports): void
    {
        $reports->generate($this->report, $this->user);
    }

    public function failed(?Throwable $exception): void
    {
        app(ScanReportService::class)->fail($this->report, $this->user);
    }
}
