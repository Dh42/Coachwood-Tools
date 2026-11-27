<?php

namespace App\Services\AspTracker;

use Illuminate\Support\Facades\DB;

/**
 * Gives subscription lines a list price. A selling plan's discount is baked
 * into the line's own original price, so on its own a subscription line
 * would look undiscounted. User decision (2026-10-19): list = what the same
 * variant sold for on one-time orders around that date, and the gap is
 * stored as selling_plan_discount — its own bucket next to line- and
 * order-level discounts.
 *
 * Per line, in order (list_price_source records which one won):
 *   prior    — the most common one-time original price on the latest day
 *              with a one-time sale, on or before the order date, within
 *              list_price_window_days
 *   after    — same, but the earliest day after, within the window
 *   variant  — the variant's current price (captured at ingest)
 *   none     — nothing to go on; list = the line's own price, no discount
 * List is never below the line's own price (no negative discount).
 *
 * Only lines with no list_price_source yet are touched, so this is cheap to
 * call after every batch. 'variant'/'none' lines can be re-resolved later
 * (e.g. after more history lands) with resolve($store, retryFallbacks: true).
 * The days of every line it prices are rebuilt in the daily rollups.
 */
class ListPriceResolver
{
    private const VARIANT_CHUNK = 50;

    public function __construct(private readonly DailyRollup $rollup = new DailyRollup) {}

    /** @return int lines resolved */
    public function resolve(string $store, bool $retryFallbacks = false): int
    {
        $pending = DB::table('asp_sales_lines')
            ->where('store', $store)
            ->where('is_subscription', true)
            ->where(function ($q) use ($retryFallbacks) {
                $q->whereNull('list_price_source');
                if ($retryFallbacks) {
                    $q->orWhereIn('list_price_source', ['variant', 'none']);
                }
            });

        $variantIds = (clone $pending)->distinct()->pluck('variant_id')->all();
        $window = (int) config('asp_tracker.list_price_window_days', 90);
        $resolved = 0;

        // One transaction per variant chunk for the prices and the rollup
        // days they move, so a failed rebuild leaves those lines unresolved
        // (and retried next run) rather than priced but missing from the
        // rollups. Per chunk rather than one for the whole run: a backfill's
        // final pass covers the store's entire history, and holding that in
        // one transaction blocked (and deadlocked with) every other ASP job.
        foreach (array_chunk($variantIds, self::VARIANT_CHUNK) as $chunk) {
            $resolved += DB::transaction(function () use ($store, $pending, $chunk, $window) {
                $history = $this->oneTimeHistory($store, array_filter($chunk, fn ($id) => $id !== null));

                $lines = (clone $pending)
                    ->where(function ($q) use ($chunk) {
                        $ids = array_values(array_filter($chunk, fn ($id) => $id !== null));
                        $q->whereIn('variant_id', $ids === [] ? [0] : $ids);
                        if (in_array(null, $chunk, true)) {
                            $q->orWhereNull('variant_id');
                        }
                    })
                    ->get(['line_item_id', 'variant_id', 'order_date', 'quantity', 'original_unit_price', 'variant_price']);

                $updates = [];
                $days = [];
                foreach ($lines as $line) {
                    [$list, $source] = $this->listPriceFor($line, $history[$line->variant_id] ?? null, $window);
                    $original = (float) $line->original_unit_price;
                    $list = max($list, $original);
                    $days[substr((string) $line->order_date, 0, 10)] = true;
                    $updates[] = [
                        'id' => $line->line_item_id,
                        'list' => round($list, 2),
                        'source' => $source,
                        'discount' => round(($list - $original) * $line->quantity, 2),
                    ];
                }

                foreach (array_chunk($updates, 500) as $batch) {
                    $this->applyUpdates($batch);
                }
                $this->rollup->rebuildDates($store, array_keys($days));

                return count($updates);
            }, DailyRollup::DEADLOCK_ATTEMPTS);
        }

        return $resolved;
    }

    /** @return array<int, array{dates: list<string>, prices: array<string, float>}> keyed by variant id */
    private function oneTimeHistory(string $store, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = DB::table('asp_sales_lines')
            ->where('store', $store)
            ->whereIn('variant_id', $variantIds)
            ->where('is_subscription', false)
            ->where('original_unit_price', '>', 0)
            ->groupBy('variant_id', 'order_date', 'original_unit_price')
            ->orderBy('variant_id')->orderBy('order_date')
            ->get(['variant_id', 'order_date', 'original_unit_price', DB::raw('COUNT(*) as n')]);

        // Most common price per variant per day; a tie goes to the higher
        // price (an odd discounted-at-source line shouldn't set the list).
        $best = [];
        foreach ($rows as $row) {
            $date = substr((string) $row->order_date, 0, 10);
            $price = (float) $row->original_unit_price;
            $current = $best[$row->variant_id][$date] ?? null;
            if ($current === null || $row->n > $current['n'] || ($row->n == $current['n'] && $price > $current['price'])) {
                $best[$row->variant_id][$date] = ['n' => $row->n, 'price' => $price];
            }
        }

        $history = [];
        foreach ($best as $variantId => $byDate) {
            ksort($byDate);
            $history[$variantId] = [
                'dates' => array_keys($byDate),
                'prices' => array_map(fn ($b) => $b['price'], $byDate),
            ];
        }

        return $history;
    }

    /** @return array{0: float, 1: string} */
    private function listPriceFor(object $line, ?array $history, int $window): array
    {
        $date = substr((string) $line->order_date, 0, 10);

        if ($history !== null) {
            $dates = $history['dates'];
            // Index of the last date <= $date (binary search).
            $lo = 0;
            $hi = count($dates) - 1;
            $prior = -1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($dates[$mid] <= $date) {
                    $prior = $mid;
                    $lo = $mid + 1;
                } else {
                    $hi = $mid - 1;
                }
            }

            if ($prior >= 0 && self::daysBetween($dates[$prior], $date) <= $window) {
                return [$history['prices'][$dates[$prior]], 'prior'];
            }

            $after = $prior + 1;
            if ($after < count($dates) && self::daysBetween($date, $dates[$after]) <= $window) {
                return [$history['prices'][$dates[$after]], 'after'];
            }
        }

        if ($line->variant_price !== null && (float) $line->variant_price > 0) {
            return [(float) $line->variant_price, 'variant'];
        }

        return [(float) $line->original_unit_price, 'none'];
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) ((strtotime($to) - strtotime($from)) / 86400);
    }

    /** One UPDATE per batch via CASE — works the same on MariaDB and SQLite. */
    private function applyUpdates(array $batch): void
    {
        $ids = array_column($batch, 'id');
        $listCase = $sourceCase = $discountCase = '';
        $listBindings = $sourceBindings = $discountBindings = [];

        foreach ($batch as $u) {
            $listCase .= ' WHEN ? THEN ?';
            array_push($listBindings, $u['id'], $u['list']);
            $sourceCase .= ' WHEN ? THEN ?';
            array_push($sourceBindings, $u['id'], $u['source']);
            $discountCase .= ' WHEN ? THEN ?';
            array_push($discountBindings, $u['id'], $u['discount']);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        DB::update(
            "UPDATE asp_sales_lines SET
                list_unit_price = CASE line_item_id{$listCase} END,
                list_price_source = CASE line_item_id{$sourceCase} END,
                selling_plan_discount = CASE line_item_id{$discountCase} END
             WHERE line_item_id IN ({$placeholders})",
            array_merge($listBindings, $sourceBindings, $discountBindings, $ids)
        );
    }
}
