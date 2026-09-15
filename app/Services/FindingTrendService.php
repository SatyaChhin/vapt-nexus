<?php

namespace App\Services;

use App\Enums\Severity;
use App\Enums\VulnerabilityState;
use App\Models\FindingSnapshot;
use App\Models\Project;
use App\Models\User;
use App\Models\VulnerabilityInstance;
use Illuminate\Support\Facades\DB;

/**
 * Daily history of open findings. Imports replace a scan's findings in place,
 * so the counts are copied into finding_snapshots (one row per project and
 * day) whenever they may have changed.
 */
class FindingTrendService
{
    /** Days of history sent to the dashboard; the chart picks shorter ranges itself. */
    public const MAX_DAYS = 365;

    public function capture(Project $project): void
    {
        $this->captureMany([$project->id]);
    }

    /**
     * Snapshots every project, including those with no open findings (zeros
     * are history too). Returns the number of projects captured.
     */
    public function captureAll(): int
    {
        $ids = Project::query()->pluck('id')->all();
        $this->captureMany($ids);

        return count($ids);
    }

    /**
     * Open findings per day, summed over the projects the user can see. A
     * project's latest snapshot carries forward over days without one.
     * Starts at the first day that has any data.
     *
     * @return list<array<string, int|string>>
     */
    public function trend(User $user, int $days = self::MAX_DAYS): array
    {
        $projectIds = Project::query()->visibleTo($user)->pluck('id')->all();
        $today = today();
        $start = $today->subDays($days - 1)->toDateString();
        $columns = ['project_id', 'captured_on', ...Severity::keys()];

        // Each project's last known state before the window opens.
        $baseline = FindingSnapshot::query()
            ->whereIn('id', FindingSnapshot::query()
                ->selectRaw('max(id)')
                ->whereIn('project_id', $projectIds)
                ->where('captured_on', '<', $start)
                ->groupBy('project_id'))
            ->toBase()
            ->get($columns);

        $byDay = FindingSnapshot::query()
            ->whereIn('project_id', $projectIds)
            ->where('captured_on', '>=', $start)
            ->toBase()
            ->get($columns)
            ->groupBy(fn (object $row) => substr((string) $row->captured_on, 0, 10));

        /** @var array<int, array<string, int>> $current */
        $current = [];
        foreach ($baseline as $row) {
            $current[(int) $row->project_id] = $this->counts($row);
        }

        $points = [];
        for ($day = $today->subDays($days - 1); $day->lte($today); $day = $day->addDay()) {
            $date = $day->toDateString();

            foreach ($byDay[$date] ?? [] as $row) {
                $current[(int) $row->project_id] = $this->counts($row);
            }

            if ($current === []) {
                continue;
            }

            $point = ['date' => $date];
            foreach (Severity::keys() as $key) {
                $point[$key] = array_sum(array_column($current, $key));
            }
            $points[] = $point;
        }

        return $points;
    }

    /**
     * @param  list<int>  $projectIds
     */
    private function captureMany(array $projectIds): void
    {
        if ($projectIds === []) {
            return;
        }

        $counts = VulnerabilityInstance::query()
            ->whereIn('project_id', $projectIds)
            ->where('state', VulnerabilityState::Open)
            ->toBase()
            ->select('project_id', 'severity', DB::raw('count(*) as total'))
            ->groupBy('project_id', 'severity')
            ->get()
            ->groupBy('project_id');

        $now = now();
        $rows = [];
        foreach ($projectIds as $projectId) {
            $totals = ($counts[$projectId] ?? collect())->pluck('total', 'severity');
            $row = ['project_id' => $projectId, 'captured_on' => $now->toDateString()];

            foreach (Severity::cases() as $severity) {
                $row[$severity->key()] = (int) ($totals[$severity->value] ?? 0);
            }

            $rows[] = [...$row, 'created_at' => $now, 'updated_at' => $now];
        }

        // Plain date strings: the model's date cast would store a time part,
        // which SQLite would then compare as a different day.
        FindingSnapshot::query()->upsert($rows, ['project_id', 'captured_on'], [...Severity::keys(), 'updated_at']);
    }

    /**
     * @return array<string, int>
     */
    private function counts(object $row): array
    {
        $counts = [];
        foreach (Severity::keys() as $key) {
            $counts[$key] = (int) $row->{$key};
        }

        return $counts;
    }
}
