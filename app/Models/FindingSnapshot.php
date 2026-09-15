<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Open findings per severity for one project on one day. Written by
 * FindingTrendService after every import and once a day by the scheduler.
 *
 * @property int $id
 * @property int $project_id
 * @property Carbon $captured_on
 * @property int $critical
 * @property int $high
 * @property int $medium
 * @property int $low
 * @property int $info
 */
#[Fillable(['project_id', 'captured_on', 'critical', 'high', 'medium', 'low', 'info'])]
class FindingSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
