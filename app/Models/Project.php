<?php

namespace App\Models;

use App\Enums\ProjectEnvironment;
use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A client/application under assessment. Every scan, finding, raw result and
 * report belongs to exactly one project.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property ProjectEnvironment $environment
 * @property ProjectStatus $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'environment', 'status'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'environment' => 'lab',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'environment' => ProjectEnvironment::class,
            'status' => ProjectStatus::class,
        ];
    }

    /**
     * Projects the user may see: all for admins, memberships for everyone else.
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->whereHas('members', fn (Builder $members) => $members->whereKey($user->getKey()));
    }

    /**
     * Path of a file or folder for this project on the private "nessus" disk,
     * e.g. storagePath('scans', '2026', '09', '14', '101', 'raw').
     */
    public function storagePath(string ...$segments): string
    {
        return implode('/', ['projects', $this->code, ...$segments]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return BelongsToMany<NessusServer, $this>
     */
    public function nessusServers(): BelongsToMany
    {
        return $this->belongsToMany(NessusServer::class, 'project_nessus_servers')->withTimestamps();
    }

    /**
     * @return HasMany<Scan, $this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
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
    public function rawScanResults(): HasMany
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

    /**
     * @return HasMany<FindingSnapshot, $this>
     */
    public function findingSnapshots(): HasMany
    {
        return $this->hasMany(FindingSnapshot::class);
    }
}
