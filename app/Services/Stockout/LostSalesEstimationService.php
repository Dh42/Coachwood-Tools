<?php

namespace App\Services\Stockout;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deliberately simplified per the user's explicit direction 2026-09-28 —
 * see the lost_sales_estimates migration's own docblock for what this
 * replaces from the brief's §7. For each stockout interval:
 *
 * 1. Walk backward day by day from the day before it started, skipping any
 *    day `inventory_history_daily` flags as out-of-stock for that product —
 *    these are the "clean" (actually in-stock) days a baseline can be
 *    trusted from.
 * 2. Average the most recent 3 clean days' sales. If they sold fewer than
 *    3 total units combined (too thin to mean anything for a single-digit-
 *    a-day SKU), widen to the most recent 14 clean days instead.
 * 3. Multiply the resulting per-day average by the interval's duration —
 *    or, for a product with an RTD-tetra -> PET-bottle successor (see
 *    RtdPetSuccessorResolver), only the sub-range of days the successor was
 *    ALSO unavailable, per the user's own framing 2026-09-28: a day the
 *    successor was in stock isn't a real lost sale, since the customer had
 *    a substitute. This can split one interval into several estimate rows
 *    (or zero, if the successor covered every day of it) — all sharing the
 *    same variant_availability_interval_id and baseline, computed once from
 *    the ORIGINAL interval's own pre-start lookback, never per sub-range.
 *
 * The backward walk never crosses the actual data floor (the earliest day
 * `sales_history_daily` actually covers) — treating a day before that as
 * "no data," never as a fabricated zero-sales day. Without this, a
 * stockout early in the imported window would silently walk into
 * unimported history and understate its own baseline. The same "no data
 * before this date" caution applies to a successor's own floor: a day
 * before the successor's earliest recorded appearance is never treated as
 * "successor was in stock," since we have no evidence it existed yet.
 */
class LostSalesEstimationService
{
    private const PRIMARY_WINDOW_DAYS = 3;

    private const FALLBACK_WINDOW_DAYS = 14;

    private const MIN_UNITS_FOR_PRIMARY_WINDOW = 3;

    // Hard stop on the backward walk regardless of the data floor — a
    // product with no clean day in two months of looking has nothing
    // meaningful to average, not just a widened window.
    private const MAX_LOOKBACK_DAYS = 60;

    private const MODEL_VERSION = 'v1-trailing-baseline';

    public function __construct(protected RtdPetSuccessorResolver $successorResolver) {}

    /** @return array{estimates_written: int, skipped_no_baseline: int, fully_substituted: int} */
    public function estimate(): array
    {
        $dataFloor = DB::table('sales_history_daily')->min('day');

        if ($dataFloor === null) {
            return ['estimates_written' => 0, 'skipped_no_baseline' => 0, 'fully_substituted' => 0];
        }

        $dataFloor = Carbon::parse($dataFloor);

        $oosDays = [];
        foreach (DB::table('inventory_history_daily')->select('product_title', 'day')->get() as $row) {
            $oosDays[$row->product_title][(string) $row->day] = true;
        }

        $sales = [];
        foreach (DB::table('sales_history_daily')->select('product_title', 'day', 'net_items_sold', 'net_sales')->get() as $row) {
            $sales[$row->product_title][(string) $row->day] = ['units' => (int) $row->net_items_sold, 'revenue' => (float) $row->net_sales];
        }

        $successors = $this->successorResolver->resolve();

        $intervals = DB::table('variant_availability_intervals')->orderBy('started_at')->get();

        $now = now();
        $rows = [];
        $skipped = 0;
        $fullySubstituted = 0;

        foreach ($intervals as $interval) {
            $title = $interval->product_title;

            if ($title === null) {
                $skipped++;

                continue;
            }

            $startedAt = Carbon::parse($interval->started_at)->startOfDay();
            $wasOngoing = $interval->ended_at === null;
            $endedAt = $wasOngoing ? $now->copy()->startOfDay() : Carbon::parse($interval->ended_at)->startOfDay();

            $baseline = $this->computeBaseline(
                $oosDays[$title] ?? [],
                $sales[$title] ?? [],
                $startedAt,
                $dataFloor
            );

            if ($baseline === null) {
                $skipped++;

                continue;
            }

            $successorTitle = $successors[mb_strtolower(trim($title))] ?? null;
            $successorFloor = $successorTitle !== null
                ? $this->earliestKnownDay($oosDays[$successorTitle] ?? [], $sales[$successorTitle] ?? [])
                : null;

            [$ranges, $excludedDays, $totalDays] = $this->splitBySuccessorAvailability(
                $startedAt,
                $endedAt,
                $successorTitle !== null ? ($oosDays[$successorTitle] ?? []) : null,
                $successorFloor
            );

            if ($ranges === []) {
                $fullySubstituted++;

                continue;
            }

            $note = $excludedDays > 0
                ? "Successor \"{$successorTitle}\" was in stock {$excludedDays} of the original {$totalDays} day(s) — only the day(s) both were unavailable are counted."
                : null;

            foreach ($ranges as $range) {
                $rangeDays = $range['start']->diffInDays($range['end']) + 1;
                $rangeIsOngoing = $wasOngoing && $range['end']->equalTo($endedAt);

                $rows[] = [
                    'variant_availability_interval_id' => $interval->id,
                    'variant_id' => $interval->variant_id,
                    'sku' => $interval->sku,
                    'product_title' => $title,
                    'started_at' => $range['start'],
                    'ended_at' => $rangeIsOngoing ? null : $range['end']->copy()->endOfDay(),
                    'days_out_of_stock' => $rangeDays,
                    'baseline_units_per_day' => $baseline['units_per_day'],
                    'baseline_revenue_per_day' => $baseline['revenue_per_day'],
                    'baseline_window_days_used' => $baseline['window_days_used'],
                    'baseline_confidence' => $baseline['confidence'],
                    'substitution_note' => $note,
                    'gross_units_lost' => round($baseline['units_per_day'] * $rangeDays, 2),
                    'gross_revenue_lost' => round($baseline['revenue_per_day'] * $rangeDays, 2),
                    'model_version' => self::MODEL_VERSION,
                    'computed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('lost_sales_estimates')->insert($chunk);
        }

        return ['estimates_written' => count($rows), 'skipped_no_baseline' => $skipped, 'fully_substituted' => $fullySubstituted];
    }

    /**
     * Splits [start, end] into the sub-ranges that still count as a real
     * stockout once a successor's own availability is accounted for. A day
     * is excluded (successor covered it) only when it's on/after the
     * successor's own earliest known day AND the successor wasn't itself
     * flagged out-of-stock that day.
     *
     * @param  ?array<string, true>  $successorOosDays  null when there's no successor at all
     * @return array{0: list<array{start: Carbon, end: Carbon}>, 1: int, 2: int} ranges, excluded day count, total original days
     */
    private function splitBySuccessorAvailability(Carbon $start, Carbon $end, ?array $successorOosDays, ?Carbon $successorFloor): array
    {
        $totalDays = $start->diffInDays($end) + 1;

        if ($successorOosDays === null) {
            return [[['start' => $start, 'end' => $end]], 0, $totalDays];
        }

        $ranges = [];
        $rangeStart = null;
        $excluded = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $successorAvailable = $successorFloor !== null
                && $cursor->gte($successorFloor)
                && ! isset($successorOosDays[$cursor->toDateString()]);

            if ($successorAvailable) {
                $excluded++;

                if ($rangeStart !== null) {
                    $ranges[] = ['start' => $rangeStart, 'end' => $cursor->copy()->subDay()];
                    $rangeStart = null;
                }
            } elseif ($rangeStart === null) {
                $rangeStart = $cursor->copy();
            }

            $cursor->addDay();
        }

        if ($rangeStart !== null) {
            $ranges[] = ['start' => $rangeStart, 'end' => $end->copy()];
        }

        return [$ranges, $excluded, $totalDays];
    }

    /** @param  array<string, true>  $oosDaySet
     * @param  array<string, array{units: int, revenue: float}>  $salesByDay */
    private function earliestKnownDay(array $oosDaySet, array $salesByDay): ?Carbon
    {
        $days = array_merge(array_keys($oosDaySet), array_keys($salesByDay));

        if ($days === []) {
            return null;
        }

        sort($days);

        return Carbon::parse($days[0]);
    }

    /**
     * @param  array<string, true>  $oosDaySet
     * @param  array<string, array{units: int, revenue: float}>  $salesByDay
     * @return ?array{units_per_day: float, revenue_per_day: float, window_days_used: int, confidence: string}
     */
    private function computeBaseline(array $oosDaySet, array $salesByDay, Carbon $before, Carbon $dataFloor): ?array
    {
        $cleanDays = [];
        $cursor = $before->copy()->subDay();
        $searched = 0;

        while (count($cleanDays) < self::FALLBACK_WINDOW_DAYS && $searched < self::MAX_LOOKBACK_DAYS && $cursor->gte($dataFloor)) {
            $dateStr = $cursor->toDateString();
            $searched++;

            if (! isset($oosDaySet[$dateStr])) {
                $cleanDays[] = $salesByDay[$dateStr] ?? ['units' => 0, 'revenue' => 0.0];
            }

            $cursor->subDay();
        }

        if ($cleanDays === []) {
            return null;
        }

        $primary = array_slice($cleanDays, 0, self::PRIMARY_WINDOW_DAYS);

        // Only "normal" confidence if the full 3-day window was actually
        // available (not cut short by the data floor or a long OOS run
        // eating into the lookback) AND it cleared the thin-sample floor.
        if (count($primary) === self::PRIMARY_WINDOW_DAYS && array_sum(array_column($primary, 'units')) >= self::MIN_UNITS_FOR_PRIMARY_WINDOW) {
            return $this->summarize($primary, 'normal');
        }

        $fallback = array_slice($cleanDays, 0, self::FALLBACK_WINDOW_DAYS);

        return $this->summarize($fallback, count($fallback) < self::FALLBACK_WINDOW_DAYS ? 'insufficient_data' : 'thin_fallback');
    }

    /** @param  list<array{units: int, revenue: float}>  $days */
    private function summarize(array $days, string $confidence): array
    {
        $count = count($days);

        return [
            'units_per_day' => array_sum(array_column($days, 'units')) / $count,
            'revenue_per_day' => array_sum(array_column($days, 'revenue')) / $count,
            'window_days_used' => $count,
            'confidence' => $confidence,
        ];
    }
}
