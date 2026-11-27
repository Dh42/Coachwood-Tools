<?php

namespace App\Services\Stockout;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns raw `inventory_history_daily` rows into `variant_availability_intervals`
 * — one row per (eligible variant, contiguous run of out-of-stock days),
 * per the brief's §6 output shape. Source data here is exclusively the
 * ShopifyQL historical import for now (Phase 1's exact snapshot data was
 * only just re-enabled and has no meaningful history yet) — every interval
 * this produces is tagged `source: reconstructed`, never `snapshot`.
 *
 * Idempotent by full replacement: every run deletes and rewrites all
 * `reconstructed` rows rather than diffing, since the input
 * (`inventory_history_daily`) is itself idempotently upserted and this
 * derivation is a pure function of it — cheap to recompute in full, and
 * avoids incremental-merge edge cases entirely. `snapshot`/`inferred` rows
 * (once those sources exist) are untouched by this class.
 */
class StockoutIntervalDerivationService
{
    public function __construct(protected CatalogEligibilityResolver $catalog) {}

    /** @return array{intervals_written: int, products_processed: int, products_skipped: int} */
    public function derive(): array
    {
        $eligibility = $this->catalog->resolve();

        $rows = DB::table('inventory_history_daily')
            ->orderBy('product_title')
            ->orderBy('day')
            ->get(['product_title', 'day']);

        $grouped = $rows->groupBy('product_title');

        $intervals = [];
        $productsSkipped = 0;
        $now = now();

        foreach ($grouped as $title => $days) {
            $key = mb_strtolower(trim((string) $title));
            $entry = $eligibility[$key] ?? null;

            if ($entry === null) {
                $productsSkipped++;

                continue;
            }

            $variants = $entry['variants'];
            $confidence = count($variants) === 1 ? 'day_level_single_variant' : 'day_level_multi_variant';
            $notes = count($variants) > 1
                ? sprintf(
                    'Product-level daily figure applied to all %d eligible variant(s) of "%s" — ShopifyQL groups by product_title, not variant/SKU.',
                    count($variants),
                    $entry['title']
                )
                : null;

            foreach ($this->mergeConsecutiveDays($days->pluck('day')) as [$start, $end]) {
                foreach ($variants as $variant) {
                    $intervals[] = [
                        'variant_id' => $variant['id'],
                        'sku' => $variant['sku'],
                        'product_title' => $entry['title'],
                        'started_at' => $start->copy()->startOfDay(),
                        'ended_at' => $end->copy()->endOfDay(),
                        'source' => 'reconstructed',
                        'confidence' => $confidence,
                        'notes' => $notes,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::table('variant_availability_intervals')->where('source', 'reconstructed')->delete();

        foreach (array_chunk($intervals, 500) as $chunk) {
            DB::table('variant_availability_intervals')->insert($chunk);
        }

        return [
            'intervals_written' => count($intervals),
            'products_processed' => $grouped->count() - $productsSkipped,
            'products_skipped' => $productsSkipped,
        ];
    }

    /**
     * @param  Collection<int, string>  $days
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function mergeConsecutiveDays($days): array
    {
        $sorted = $days->map(fn (string $d) => Carbon::parse($d))->sort()->values();

        $ranges = [];
        $rangeStart = null;
        $prev = null;

        foreach ($sorted as $day) {
            if ($rangeStart === null) {
                $rangeStart = $day;
            } elseif (! $day->isSameDay($prev->copy()->addDay())) {
                $ranges[] = [$rangeStart, $prev];
                $rangeStart = $day;
            }

            $prev = $day;
        }

        if ($rangeStart !== null) {
            $ranges[] = [$rangeStart, $prev];
        }

        return $ranges;
    }
}
