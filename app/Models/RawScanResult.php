<?php

namespace App\Models;

use Database\Factories\RawScanResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An original Nessus export preserved on the private "nessus" disk.
 *
 * @property int $id
 * @property int $project_id
 * @property int $scan_id
 * @property string $format
 * @property string $file_path
 * @property string $checksum SHA-256 of the file contents.
 * @property int|null $size_bytes
 * @property Carbon|null $imported_at
 */
#[Fillable(['format', 'file_path', 'checksum', 'size_bytes', 'imported_at'])]
class RawScanResult extends Model
{
    /** @use HasFactory<RawScanResultFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
