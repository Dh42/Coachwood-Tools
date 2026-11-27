<?php

namespace App\Services\Cdq;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqDefect;
use App\Models\CdqListingSnapshot;
use App\Models\CdqScore;
use App\Models\Product;
use App\Models\VcTrafficCurrent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CDQ Phase 5 — read-only aggregation over cdq_score/cdq_defect for the
 * report dashboard. Always scoped to the currently-effective check-config
 * revision — a report mixing scores from different revisions would make
 * "why did this drop" unanswerable, exactly what versioning cdq_score was
 * built to prevent.
 */
class CdqReportService
{
    private CdqCheckConfigVersion $version;

    public function __construct()
    {
        $this->version = CdqCheckConfigVersion::orderByDesc('effective_from')->firstOrFail();
    }

    /**
     * Batched CDQ scores keyed by ASIN, for other tools (VC Birdseye, the
     * VC Listings Editor) that want to surface the score without owning
     * this service's own fail-fast construction behavior — returns []
     * instead of throwing when CDQ hasn't been seeded with a check-config
     * revision yet on this environment.
     */
    public static function currentScoresByAsin(): array
    {
        if (! CdqCheckConfigVersion::query()->exists()) {
            return [];
        }

        return (new self)->currentScores()->keyBy('asin')->all();
    }

    /** Latest cdq_score per ASIN under the current config version, joined with product title and glance views. */
    public function currentScores(): Collection
    {
        $latestSnapshotIds = CdqListingSnapshot::selectRaw('MAX(id) as id')->groupBy('asin', 'marketplace_id')->pluck('id');

        $scores = CdqScore::where('check_config_version_id', $this->version->id)
            ->whereIn('snapshot_id', $latestSnapshotIds)
            ->with('snapshot')
            ->get();

        $titles = Product::whereIn('asin', $scores->pluck('snapshot.asin'))->pluck('title', 'asin');
        $glanceViews = VcTrafficCurrent::whereIn('asin', $scores->pluck('snapshot.asin'))->pluck('glance_views', 'asin');

        return $scores->map(function (CdqScore $score) use ($titles, $glanceViews) {
            $asin = $score->snapshot->asin;

            return [
                'asin' => $asin,
                'sku' => $score->snapshot->sku,
                'title' => $titles[$asin] ?? null,
                'parent_asin' => $score->snapshot->parent_asin,
                'grade' => $score->grade,
                'total_score' => $score->total_score !== null ? (float) $score->total_score : null,
                'attr_score' => (float) $score->attr_score,
                'text_score' => (float) $score->text_score,
                'image_score' => (float) $score->image_score,
                'aplus_score' => (float) $score->aplus_score,
                'consist_score' => (float) $score->consist_score,
                'consist_checked' => $score->consist_checked_at !== null,
                'glance_views' => $glanceViews[$asin] ?? null,
                'points_lost' => $score->total_score !== null ? round(100 - (float) $score->total_score, 2) : 100.0,
                'snapshot_id' => $score->snapshot_id,
            ];
        });
    }

    /** @return array<string, int> */
    public function gradeDistribution(): array
    {
        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'U' => 0];
        foreach ($this->currentScores() as $row) {
            $counts[$row['grade']]++;
        }

        return $counts;
    }

    /** 10-point score buckets, 0–100, for a distribution histogram. Grade U (null score) is excluded — it isn't a point on a 0–100 scale. */
    public function scoreHistogram(): array
    {
        $buckets = array_fill(0, 10, 0);
        foreach ($this->currentScores() as $row) {
            if ($row['total_score'] === null) {
                continue;
            }
            $bucket = min(9, intdiv((int) $row['total_score'], 10));
            $buckets[$bucket]++;
        }

        return $buckets;
    }

    /**
     * Compares each ASIN's current latest score against its latest score
     * from ~7 days ago (same config-version scoping). Cadence is weekly
     * per the brief's snapshot schedule, so this is legitimately empty on
     * a catalog with under a week of real history — reports that honestly,
     * it doesn't fabricate a comparison.
     */
    public function weekOverWeekMovement(): array
    {
        $cutoff = Carbon::now()->subDays(7);
        $priorSnapshotIds = CdqListingSnapshot::where('captured_at', '<=', $cutoff)
            ->selectRaw('MAX(id) as id')->groupBy('asin', 'marketplace_id')->pluck('id');

        if ($priorSnapshotIds->isEmpty()) {
            return ['has_prior_data' => false, 'improved' => 0, 'declined' => 0, 'unchanged' => 0, 'avg_change' => null];
        }

        $priorScores = CdqScore::whereIn('snapshot_id', $priorSnapshotIds)
            ->whereNotNull('total_score')
            ->with('snapshot')
            ->get()
            ->keyBy(fn ($s) => $s->snapshot->asin);

        $improved = 0;
        $declined = 0;
        $unchanged = 0;
        $deltas = [];

        foreach ($this->currentScores() as $row) {
            $prior = $priorScores->get($row['asin']);
            if ($prior === null || $row['total_score'] === null) {
                continue;
            }
            $delta = $row['total_score'] - (float) $prior->total_score;
            $deltas[] = $delta;
            match (true) {
                $delta > 0 => $improved++,
                $delta < 0 => $declined++,
                default => $unchanged++,
            };
        }

        return [
            'has_prior_data' => true,
            'improved' => $improved,
            'declined' => $declined,
            'unchanged' => $unchanged,
            'avg_change' => count($deltas) ? round(array_sum($deltas) / count($deltas), 2) : null,
        ];
    }

    /** @return Collection<int, array{check_code:string, dimension:string, points_lost:float, occurrences:int}> */
    public function topDefectsByPointsLost(int $limit = 15): Collection
    {
        $latestSnapshotIds = CdqListingSnapshot::selectRaw('MAX(id) as id')->groupBy('asin', 'marketplace_id')->pluck('id');

        return CdqDefect::whereIn('snapshot_id', $latestSnapshotIds)
            ->selectRaw('check_code, dimension, SUM(points) as points_lost, COUNT(*) as occurrences')
            ->groupBy('check_code', 'dimension')
            ->orderByDesc('points_lost')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'check_code' => $row->check_code,
                'dimension' => $row->dimension,
                'points_lost' => round((float) $row->points_lost, 2),
                'occurrences' => (int) $row->occurrences,
            ]);
    }

    /**
     * Per-defect drill-down for one ASIN's latest snapshot — every defect
     * with its attribute path, per the brief's Phase 5 spec.
     */
    public function defectsForAsin(string $asin): ?array
    {
        $snapshot = CdqListingSnapshot::where('asin', $asin)->orderByDesc('id')->first();
        if ($snapshot === null) {
            return null;
        }

        $score = CdqScore::where('snapshot_id', $snapshot->id)->where('check_config_version_id', $this->version->id)->first();
        $defects = CdqDefect::where('snapshot_id', $snapshot->id)->orderByDesc('points')->get();

        return [
            'snapshot' => $snapshot,
            'score' => $score,
            'title' => Product::where('asin', $asin)->value('title'),
            'defects' => $defects,
        ];
    }

    /**
     * IMAGE dimension "rolled up to parent as a weighted mean" (brief
     * section 4) — confirmed as a Phase 5 reporting-time concern, not a
     * Phase 3 scoring-engine one (parent ASINs in this catalog have no
     * cdq_score row of their own — none are independently sellable,
     * confirmed live: 0 of 18 referenced parent_asins have their own
     * snapshot). Weighted by glance_views; falls back to an equal-weight
     * mean when no traffic data exists for a family's children (true for
     * every family right now — vc_traffic_current has 0 rows in this
     * environment).
     *
     * @return Collection<string, array{parent_asin:string, child_count:int, weighted_image_score:float, used_traffic_weighting:bool}>
     */
    public function parentImageRollup(): Collection
    {
        $rows = $this->currentScores()->filter(fn ($r) => $r['parent_asin'] !== null)->groupBy('parent_asin');

        return $rows->map(function (Collection $children, string $parentAsin) {
            $totalWeight = $children->sum(fn ($c) => $c['glance_views'] ?? 0);
            $usedTraffic = $totalWeight > 0;

            $weightedScore = $usedTraffic
                ? $children->sum(fn ($c) => $c['image_score'] * (($c['glance_views'] ?? 0) / $totalWeight))
                : $children->avg('image_score');

            return [
                'parent_asin' => $parentAsin,
                'child_count' => $children->count(),
                'weighted_image_score' => round($weightedScore, 2),
                'used_traffic_weighting' => $usedTraffic,
            ];
        });
    }

    /** @return Collection<int, array> sorted by points_lost * glance_views descending, for the work-queue CSV. */
    public function workQueue(): Collection
    {
        return $this->currentScores()
            ->map(function ($row) {
                $row['priority'] = $row['points_lost'] * ($row['glance_views'] ?? 0);

                return $row;
            })
            ->sortByDesc('priority')
            ->values();
    }
}
