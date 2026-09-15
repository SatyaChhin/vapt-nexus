<?php

namespace App\Console\Commands;

use App\Services\FindingTrendService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('findings:snapshot')]
#[Description("Record today's open findings per project for the dashboard trend")]
class FindingSnapshotCommand extends Command
{
    public function handle(FindingTrendService $trends): int
    {
        $count = $trends->captureAll();

        $this->info("Recorded open findings for {$count} project(s).");

        return self::SUCCESS;
    }
}
