<?php

namespace App\Models;

use App\Enums\NessusServerStatus;
use Database\Factories\NessusServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $base_url
 * @property string $access_key Decrypted on read; stored encrypted.
 * @property string $secret_key Decrypted on read; stored encrypted.
 * @property bool $verify_ssl
 * @property NessusServerStatus $status
 * @property string|null $server_version
 * @property string|null $last_error
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_connected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'base_url', 'access_key', 'secret_key', 'verify_ssl'])]
#[Hidden(['access_key', 'secret_key'])]
class NessusServer extends Model
{
    /** @use HasFactory<NessusServerFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'verify_ssl' => true,
        'status' => 'unknown',
    ];

    protected function casts(): array
    {
        return [
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'verify_ssl' => 'boolean',
            'status' => NessusServerStatus::class,
            'last_checked_at' => 'datetime',
            'last_connected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_nessus_servers')->withTimestamps();
    }

    /**
     * @return HasMany<Scan, $this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }
}
