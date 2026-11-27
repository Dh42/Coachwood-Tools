<?php

namespace App\Services\TeamAppStats;

use App\Models\TeamAppAdSnapshot;
use App\Models\TeamAppCategorySnapshot;
use App\Models\TeamAppSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard data assembler for BioSteel App Stats — ported from the legacy
 * standalone tool's index.php query section. Bucketing/growth is computed in
 * PHP over one fetched Collection (rather than legacy's raw
 * DATE()/YEARWEEK() SQL) for the same reason PoStatsController already
 * documents: it keeps this portable across this app's MySQL (prod) and
 * SQLite (test suite) — YEARWEEK() has no SQLite equivalent. Row counts here
 * (hourly snapshots over at most a couple of years) are small enough that
 * this is cheap.
 */
class TeamAppStatsService
{
    /**
     * $connection is for `compare:team-app-stats`, which runs this same
     * report against the old database; null is the app's own.
     */
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * Snapshots are cumulative totals, so daily/weekly buckets take the LAST
     * snapshot in each bucket (end-of-period value); 'raw' returns every row.
     * Growth is a delta from the previous point in the (bucketed) series.
     *
     * @return list<array{label: string, users: ?int, teams: ?int, usersGrowth: ?int, teamsGrowth: ?int}>
     */
    public function series(Carbon $from, Carbon $to, string $granularity): array
    {
        $rows = TeamAppSnapshot::on($this->connection)
            ->whereBetween('captured_at', [$from, $to])
            ->orderBy('captured_at')
            ->get(['captured_at', 'users', 'teams']);

        $points = match ($granularity) {
            'daily' => $this->lastPerBucket($rows, fn ($r) => $r->captured_at->format('Y-m-d')),
            'weekly' => $this->lastPerBucket($rows, fn ($r) => $r->captured_at->format('o-W')), // ISO week
            default => $rows,
        };

        $series = [];
        $prevUsers = $prevTeams = null;

        foreach ($points as $point) {
            $users = $point->users;
            $teams = $point->teams;

            $series[] = [
                'label' => $point->captured_at->format('Y-m-d H:i:s'),
                'users' => $users,
                'teams' => $teams,
                'usersGrowth' => ($prevUsers !== null && $users !== null) ? $users - $prevUsers : null,
                'teamsGrowth' => ($prevTeams !== null && $teams !== null) ? $teams - $prevTeams : null,
            ];

            if ($users !== null) {
                $prevUsers = $users;
            }
            if ($teams !== null) {
                $prevTeams = $teams;
            }
        }

        return $series;
    }

    private function lastPerBucket(Collection $rows, callable $bucketKey): Collection
    {
        return $rows->groupBy($bucketKey)->map(fn (Collection $bucket) => $bucket->last())->values();
    }

    /**
     * Latest category (sport) breakdown within the range — each category's
     * share of the current total, plus its growth since the earliest
     * snapshot-with-categories in the range.
     *
     * @return list<array{category: string, teams: int, pct: ?float, growth: ?int}>
     */
    public function categoryBreakdown(Carbon $from, Carbon $to): array
    {
        $latest = TeamAppSnapshot::on($this->connection)
            ->whereBetween('captured_at', [$from, $to])
            ->whereNotNull('teams')
            ->orderByDesc('captured_at')
            ->first();

        $categories = $latest
            ? $latest->categories()->orderByDesc('teams')->get()
            : collect();

        $earliestSnapshotId = TeamAppSnapshot::on($this->connection)
            ->whereBetween('captured_at', [$from, $to])
            ->whereHas('categories')
            ->orderBy('captured_at')
            ->value('id');

        $firstByCategory = $earliestSnapshotId
            ? TeamAppCategorySnapshot::on($this->connection)->where('snapshot_id', $earliestSnapshotId)->pluck('teams', 'category')
            : collect();

        $total = (int) $categories->sum('teams');

        return $categories->map(function (TeamAppCategorySnapshot $c) use ($firstByCategory, $total) {
            $prev = $firstByCategory->has($c->category) ? (int) $firstByCategory[$c->category] : null;
            $teams = (int) $c->teams;

            return [
                'category' => $c->category,
                'teams' => $teams,
                'pct' => $total > 0 ? $teams / $total * 100 : null,
                'growth' => $prev !== null ? $teams - $prev : null,
            ];
        })->values()->all();
    }

    /**
     * Ad metrics within the range (daily grain, unlike the hourly snapshots
     * above). Ratios are computed from the *summed* counts across the range,
     * not averaged per-day percentages, so a zero-traffic day can't skew the
     * range figure.
     *
     * @return array{rows: Collection<int, TeamAppAdSnapshot>, totals: array<string, int>, fillRate: ?float, ctr: ?float, viewability: ?float}
     */
    public function adTotals(Carbon $from, Carbon $to): array
    {
        $rows = TeamAppAdSnapshot::on($this->connection)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();

        $totals = [
            'impressions' => (int) $rows->sum('impressions'),
            'clicks' => (int) $rows->sum('clicks'),
            'ad_requests' => (int) $rows->sum('ad_requests'),
            'viewable' => (int) $rows->sum('viewable_impressions'),
            'measurable' => (int) $rows->sum('measurable_impressions'),
        ];

        return [
            'rows' => $rows,
            'totals' => $totals,
            'fillRate' => $totals['ad_requests'] > 0 ? $totals['impressions'] / $totals['ad_requests'] * 100 : null,
            'ctr' => $totals['impressions'] > 0 ? $totals['clicks'] / $totals['impressions'] * 100 : null,
            'viewability' => $totals['measurable'] > 0 ? $totals['viewable'] / $totals['measurable'] * 100 : null,
        ];
    }
}
