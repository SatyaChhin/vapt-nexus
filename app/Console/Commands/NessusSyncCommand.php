<?php

namespace App\Console\Commands;

use App\Services\Nessus\NessusScanSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nessus:sync')]
#[Description('Import new Nessus scans named with a project code, and re-import scans whose run has finished')]
class NessusSyncCommand extends Command
{
    public function handle(NessusScanSync $sync): int
    {
        $summary = $sync->run();

        $labels = [
            'imported' => '<fg=green>Imported</>',
            'synced' => '<fg=green>Re-synced</>',
            'running' => '<fg=blue>Running</>',
            'skipped' => '<fg=yellow>Skipped</>',
            'errors' => '<fg=red>Error</>',
        ];

        foreach ($labels as $key => $label) {
            foreach ($summary[$key] as $line) {
                $this->line("  {$label}  {$line}");
            }
        }

        if (array_merge(...array_values($summary)) === []) {
            $this->line('  Nothing new in Nessus.');
        }

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
