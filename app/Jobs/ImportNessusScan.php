<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Models\User;
use App\Services\Nessus\NessusScanImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pulls one scan's results from Nessus. Runs on the queue because a scan
 * needs one API call per host and plugin.
 */
class ImportNessusScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Scan $scan, public ?User $user = null) {}

    public function handle(NessusScanImporter $importer): void
    {
        $importer->import($this->scan, $this->user);
    }

    /**
     * Nessus errors are handled in the importer; this covers everything else.
     */
    public function failed(?Throwable $exception): void
    {
        app(NessusScanImporter::class)->fail(
            $this->scan,
            'The import failed unexpectedly. Check the application log for details.',
            $this->user,
        );
    }
}
