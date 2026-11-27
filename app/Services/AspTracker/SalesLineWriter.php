<?php

namespace App\Services\AspTracker;

use Illuminate\Support\Facades\DB;

/**
 * The one write path for asp_sales_lines — the bulk backfill, the webhook
 * queue and the nightly catch-up all go through write(), so a re-run is
 * always safe. Each order's rows replace whatever was stored for it: lines
 * no longer on the order (order edits) are deleted, unchanged lines
 * (same content_hash) aren't touched at all, so re-running the backfill
 * reports zero changes. Every day an order changed on is rebuilt in the
 * daily rollups (DailyRollup) the page reads — in the same transaction, so
 * a failed rebuild rolls the lines back too and the retry redoes both,
 * rather than leaving stored lines the rollups never picked up. A deadlock
 * with another ASP job retries the whole transaction.
 */
class SalesLineWriter
{
    // Everything except the ListPriceResolver-owned columns
    // (list_unit_price, list_price_source, selling_plan_discount) and
    // created_at — a re-fetch must not wipe a resolved list price.
    private const UPDATE_COLUMNS = [
        'store', 'order_id', 'order_name', 'order_created_at', 'order_date', 'week_start', 'source_name', 'app_name',
        'product_id', 'variant_id', 'sku', 'product_title', 'variant_title', 'quantity', 'original_unit_price',
        'variant_price', 'line_level_discount', 'order_level_discount', 'net_line_total', 'discount_labels',
        'selling_plan_name', 'is_subscription', 'is_free_item', 'refunded_qty', 'refunded_amount',
        'financial_status', 'cancelled_at', 'content_hash', 'updated_at',
    ];

    public function __construct(private readonly DailyRollup $rollup = new DailyRollup) {}

    /**
     * @param  list<array>  $mappedOrders  OrderLineMapper::map() results
     * @return array{orders: int, lines_changed: int, unattributed_refunds: int}
     */
    public function write(array $mappedOrders): array
    {
        if ($mappedOrders === []) {
            return ['orders' => 0, 'lines_changed' => 0, 'unattributed_refunds' => 0];
        }

        $orderIds = array_values(array_unique(array_column($mappedOrders, 'order_id')));

        $existing = [];
        $storedDays = [];
        foreach (array_chunk($orderIds, 500) as $chunk) {
            foreach (DB::table('asp_sales_lines')->whereIn('order_id', $chunk)->get(['line_item_id', 'order_id', 'store', 'order_date', 'content_hash']) as $row) {
                $existing[$row->order_id][$row->line_item_id] = $row->content_hash;
                $storedDays[$row->order_id][$row->store][substr((string) $row->order_date, 0, 10)] = true;
            }
        }

        $now = now()->format('Y-m-d H:i:s');
        $upserts = [];
        $staleLineIds = [];
        $dirtyOrderIds = [];
        $discounts = [];
        $dirtyDays = [];
        $changed = 0;
        $unattributed = 0;

        foreach ($mappedOrders as $mapped) {
            $orderId = $mapped['order_id'];
            $stored = $existing[$orderId] ?? [];
            $unattributed += $mapped['unattributed_refunds'];

            $lines = $mapped['skip'] ? [] : $mapped['lines'];
            $keep = [];
            $dirty = false;

            foreach ($lines as $row) {
                $keep[$row['line_item_id']] = true;
                if (($stored[$row['line_item_id']] ?? null) !== $row['content_hash']) {
                    $upserts[] = $row + ['created_at' => $now, 'updated_at' => $now];
                    $changed++;
                    $dirty = true;
                }
            }

            foreach (array_keys($stored) as $lineId) {
                if (! isset($keep[$lineId])) {
                    $staleLineIds[] = $lineId;
                    $changed++;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $dirtyOrderIds[] = $orderId;
                $dirtyDays = array_replace_recursive($dirtyDays, $storedDays[$orderId] ?? []);
                foreach ($lines as $row) {
                    $dirtyDays[$row['store']][$row['order_date']] = true;
                }
                foreach ($mapped['skip'] ? [] : $mapped['discounts'] as $discount) {
                    $discounts[] = $discount;
                }
            }
        }

        DB::transaction(function () use ($staleLineIds, $dirtyOrderIds, $discounts, $upserts, $dirtyDays) {
            foreach (array_chunk($staleLineIds, 500) as $chunk) {
                DB::table('asp_sales_lines')->whereIn('line_item_id', $chunk)->delete();
            }
            foreach (array_chunk($dirtyOrderIds, 500) as $chunk) {
                DB::table('asp_line_discounts')->whereIn('order_id', $chunk)->delete();
            }
            foreach (array_chunk($discounts, 500) as $chunk) {
                DB::table('asp_line_discounts')->insert($chunk);
            }
            foreach (array_chunk($upserts, 500) as $chunk) {
                DB::table('asp_sales_lines')->upsert($chunk, ['line_item_id'], self::UPDATE_COLUMNS);
            }
            foreach ($dirtyDays as $store => $days) {
                $this->rollup->rebuildDates($store, array_keys($days));
            }
        }, DailyRollup::DEADLOCK_ATTEMPTS);

        return ['orders' => count($orderIds), 'lines_changed' => $changed, 'unattributed_refunds' => $unattributed];
    }
}
