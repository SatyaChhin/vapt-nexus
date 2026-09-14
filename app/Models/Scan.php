<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Database\Factories\ScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $nessus_server_id
 * @property int|null $nessus_scan_id
 * @property string $name
 * @property string|null $description
 * @property string $targets
 * @property ScanStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_seconds
 * @property int $total_hosts
 * @property int $scanned_hosts
 * @property int $total_findings
 * @property int $critical_count
 * @property int $high_count
 * @property int $medium_count
 * @property int $low_count
 * @property int $info_count
 * @property string|null $error_message
 * @property Carbon|null $last_polled_at
 * @property Carbon|null $imported_at
 * @property int|null $created_by
 */
#[Fillable(['name', 'description', 'targets', 'nessus_server_id'])]
class Scan extends Model
{
    /** @use HasFactory<ScanFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'created',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_polled_at' => 'datetime',
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
     * @return BelongsTo<NessusServer, $this>
     */
    public function nessusServer(): BelongsTo
    {
        return $this->belongsTo(NessusServer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ScanHost, $this>
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(ScanHost::class);
    }

    /**
     * @return HasMany<VulnerabilityInstance, $this>
     */
    public function vulnerabilityInstances(): HasMany
    {
        return $this->hasMany(VulnerabilityInstance::class);
    }

    /**
     * @return HasMany<RawScanResult, $this>
     */
    public function rawResults(): HasMany
    {
        return $this->hasMany(RawScanResult::class);
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
