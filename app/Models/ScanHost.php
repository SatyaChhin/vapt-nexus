<?php

namespace App\Models;

use Database\Factories\ScanHostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $scan_id
 * @property int|null $asset_id
 * @property int|null $nessus_host_id
 * @property string|null $ip_address
 * @property string|null $hostname
 * @property string|null $fqdn
 * @property string|null $operating_system
 * @property string|null $status
 * @property int $total_findings
 * @property int $critical_count
 * @property int $high_count
 * @property int $medium_count
 * @property int $low_count
 * @property int $info_count
 */
#[Fillable([
    'asset_id', 'nessus_host_id', 'ip_address', 'hostname', 'fqdn', 'operating_system', 'status',
    'total_findings', 'critical_count', 'high_count', 'medium_count', 'low_count', 'info_count',
])]
class ScanHost extends Model
{
    /** @use HasFactory<ScanHostFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Scan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return HasMany<VulnerabilityInstance, $this>
     */
    public function vulnerabilityInstances(): HasMany
    {
        return $this->hasMany(VulnerabilityInstance::class);
    }
}
