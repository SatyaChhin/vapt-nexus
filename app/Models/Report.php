<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $scan_id
 * @property string|null $nessus_run_uuid The Nessus run this report covers.
 * @property string $report_number e.g. VULN-HC-2026-00001
 * @property string $title
 * @property string $type
 * @property ReportStatus $status
 * @property string|null $file_path
 * @property Carbon|null $generated_at
 * @property int|null $created_by
 * @property Carbon|null $deleted_at Deleted reports keep their row so the number is never reused.
 */
#[Fillable(['scan_id', 'report_number', 'title', 'type', 'status', 'file_path', 'generated_at'])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'type' => 'vulnerability',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'generated_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
