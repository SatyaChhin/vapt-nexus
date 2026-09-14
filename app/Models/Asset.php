<?php

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string|null $hostname
 * @property string|null $fqdn
 * @property string|null $ip_address
 * @property string|null $operating_system
 * @property string $asset_type
 * @property string|null $environment
 * @property string|null $owner
 * @property string $status
 */
#[Fillable(['hostname', 'fqdn', 'ip_address', 'operating_system', 'asset_type', 'environment', 'owner', 'status'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ScanHost, $this>
     */
    public function scanHosts(): HasMany
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
}
