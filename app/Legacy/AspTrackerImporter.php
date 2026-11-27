<?php

namespace App\Legacy;

use App\Services\AspTracker\DailyRollup;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies the ASP Tracker from the old app (plan section 5.6), the largest
 * import: about 1.4 million sales lines and 0.9 million discounts.
 *
 * - Orders are taken 1,000 at a time, in order ID order, each batch in its
 *   own short transaction with a pause between, so the shared server never
 *   holds one long transaction. For each order the old app has:
 *   - asp_sales_lines: upserted on Shopify's line item ID; a line this app
 *     has for that order and the old app doesn't is removed
 *   - asp_line_discounts: brought in line with the old app's for that order
 *     (IDs are this app's own; nothing points at them)
 * - asp_pending_orders: upserted on (store, order ID)
 * - asp_ingest_log: mirrored with IDs (TableMirror). Without the old
 *   backfill rows the backfill would start over at cutover
 * - the daily rollups are rebuilt per store, not copied (DailyRollup)
 *
 * The old app wins. `--dry-run` reads and counts only, writing nothing: a
 * rolled-back transaction this size would be heavy on the shared server.
 * Reports counts only.
 */
final class AspTrackerImporter
{
    private const ORDERS_PER_BATCH = 1000;

    /** Rows per upsert: 34 columns each stays well under the 65,535 placeholder limit. */
    private const ROWS_PER_UPSERT = 500;

    public const LINE_COLUMNS = ['line_item_id', 'store', 'order_id', 'order_name', 'order_created_at', 'order_date', 'week_start', 'source_name', 'app_name', 'product_id', 'variant_id', 'sku', 'product_title', 'variant_title', 'quantity', 'original_unit_price', 'list_unit_price', 'list_price_source', 'variant_price', 'line_level_discount', 'order_level_discount', 'selling_plan_discount', 'net_line_total', 'discount_labels', 'selling_plan_name', 'is_subscription', 'is_free_item', 'refunded_qty', 'refunded_amount', 'financial_status', 'cancelled_at', 'content_hash', 'created_at', 'updated_at'];

    public const DISCOUNT_COLUMNS = ['line_item_id', 'order_id', 'label', 'label_group', 'discount_type', 'level', 'amount'];

    private const PENDING_COLUMNS = ['store', 'order_id', 'reason', 'attempts', 'last_error', 'created_at', 'updated_at'];

    private const LOG_COLUMNS = ['id', 'store', 'kind', 'status', 'bulk_operation_id', 'since', 'started_at', 'finished_at', 'orders_touched', 'lines_changed', 'unattributed_refunds', 'errors', 'notes', 'created_at', 'updated_at'];

    /** @var array<string, array{read: int, new: int, updated: int, removed: int}> */
    private array $report = [];

    public function __construct(
        private readonly ConnectionInterface $legacy,
        private readonly ConnectionInterface $db,
        private readonly int $pauseMilliseconds = 250,
    ) {}

    public static function make(): self
    {
        return new self(DB::connection('legacy'), DB::connection());
    }

    /** @return array<string, array{read: int, new: int, updated: int, removed: int}> */
    public function run(bool $dryRun = false, ?callable $progress = null): array
    {
        $empty = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
        $this->report = ['asp_sales_lines' => $empty, 'asp_line_discounts' => $empty];

        $after = 0;
        $batches = 0;
        while (true) {
            $orderIds = $this->legacy->table('asp_sales_lines')->where('order_id', '>', $after)
                ->distinct()->orderBy('order_id')->limit(self::ORDERS_PER_BATCH)->pluck('order_id')
                ->map(fn ($id) => (int) $id)->all();
            if ($orderIds === []) {
                break;
            }
            if ($batches++ > 0 && $this->pauseMilliseconds > 0) {
                usleep($this->pauseMilliseconds * 1000);
            }

            $dryRun ? $this->importOrders($orderIds, true) : $this->db->transaction(fn () => $this->importOrders($orderIds, false));
            $after = end($orderIds);
            if ($progress) {
                $progress($this->report['asp_sales_lines']['read']);
            }
        }

        $this->importPending($dryRun);

        if ($dryRun) {
            $this->db->beginTransaction();
        }
        try {
            $this->report += (new TableMirror($this->legacy, $this->db, $this->pauseMilliseconds))->mirror(['asp_ingest_log' => self::LOG_COLUMNS]);
        } finally {
            if ($dryRun) {
                $this->db->rollBack();
            }
        }

        if (! $dryRun) {
            $rollup = app(DailyRollup::class);
            foreach (array_keys(config('asp_tracker.stores')) as $store) {
                $rollup->rebuildAll($store);
            }
        }

        return $this->report;
    }

    /** @param  list<int>  $orderIds */
    private function importOrders(array $orderIds, bool $dryRun): void
    {
        $oldLines = $this->legacy->table('asp_sales_lines')->whereIn('order_id', $orderIds)->get(self::LINE_COLUMNS);
        $oldIds = $oldLines->pluck('line_item_id')->map(fn ($id) => (int) $id)->flip();
        $hereIds = $this->db->table('asp_sales_lines')->whereIn('order_id', $orderIds)->pluck('line_item_id')->map(fn ($id) => (int) $id);

        $existing = $hereIds->filter(fn (int $id) => isset($oldIds[$id]))->count();
        $gone = $hereIds->reject(fn (int $id) => isset($oldIds[$id]))->values()->all();
        $this->report['asp_sales_lines']['read'] += $oldLines->count();
        $this->report['asp_sales_lines']['updated'] += $existing;
        $this->report['asp_sales_lines']['new'] += $oldLines->count() - $existing;
        $this->report['asp_sales_lines']['removed'] += count($gone);

        if (! $dryRun) {
            foreach ($oldLines->chunk(self::ROWS_PER_UPSERT) as $chunk) {
                $this->db->table('asp_sales_lines')->upsert(
                    $chunk->map(fn ($row) => (array) $row)->values()->all(),
                    ['line_item_id'],
                    array_values(array_diff(self::LINE_COLUMNS, ['line_item_id', 'created_at'])),
                );
            }
            if ($gone !== []) {
                $this->db->table('asp_sales_lines')->whereIn('line_item_id', $gone)->delete();
            }
        }

        $this->syncDiscounts($orderIds, $dryRun);
    }

    /**
     * Rows are compared as a multiset (two identical discounts on one line
     * are two rows): only the difference is deleted or inserted, so a re-run
     * reports no change.
     *
     * @param  list<int>  $orderIds
     */
    private function syncDiscounts(array $orderIds, bool $dryRun): void
    {
        $key = fn ($row) => implode("\0", array_map(fn ($c) => $c === 'amount' ? number_format((float) $row->{$c}, 2, '.', '') : (string) $row->{$c}, self::DISCOUNT_COLUMNS));

        /** @var Collection<string, Collection<int, object>> $wanted */
        $wanted = $this->legacy->table('asp_line_discounts')->whereIn('order_id', $orderIds)->get(self::DISCOUNT_COLUMNS)->groupBy($key);
        $here = $this->db->table('asp_line_discounts')->whereIn('order_id', $orderIds)->get(['id', ...self::DISCOUNT_COLUMNS])->groupBy($key);

        $delete = [];
        $insert = [];
        foreach ($here as $k => $rows) {
            $extra = $rows->count() - ($wanted[$k] ?? collect())->count();
            if ($extra > 0) {
                array_push($delete, ...$rows->take($extra)->pluck('id')->all());
            }
        }
        foreach ($wanted as $k => $rows) {
            $missing = $rows->count() - ($here[$k] ?? collect())->count();
            if ($missing > 0) {
                array_push($insert, ...$rows->take($missing)->map(fn ($row) => (array) $row)->all());
            }
        }

        $this->report['asp_line_discounts']['read'] += $wanted->flatten(1)->count();
        $this->report['asp_line_discounts']['new'] += count($insert);
        $this->report['asp_line_discounts']['removed'] += count($delete);

        if (! $dryRun) {
            foreach (array_chunk($delete, 1000) as $chunk) {
                $this->db->table('asp_line_discounts')->whereIn('id', $chunk)->delete();
            }
            foreach (array_chunk($insert, 1000) as $chunk) {
                $this->db->table('asp_line_discounts')->insert($chunk);
            }
        }
    }

    private function importPending(bool $dryRun): void
    {
        $rows = $this->legacy->table('asp_pending_orders')->orderBy('id')->get(self::PENDING_COLUMNS);
        $existing = 0;
        foreach ($rows as $row) {
            $existing += $this->db->table('asp_pending_orders')->where(['store' => $row->store, 'order_id' => $row->order_id])->exists() ? 1 : 0;
        }
        if (! $dryRun && $rows->isNotEmpty()) {
            $this->db->table('asp_pending_orders')->upsert($rows->map(fn ($row) => (array) $row)->all(), ['store', 'order_id'], ['reason', 'attempts', 'last_error', 'updated_at']);
        }

        $this->report['asp_pending_orders'] = ['read' => $rows->count(), 'new' => $rows->count() - $existing, 'updated' => $existing, 'removed' => 0];
    }
}
