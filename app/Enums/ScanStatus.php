<?php

namespace App\Enums;

enum ScanStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Importing = 'importing';
    case Imported = 'imported';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Imported, self::Failed, self::Cancelled], true);
    }
}
