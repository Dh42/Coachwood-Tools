<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies BioSteel Shopify Lost Sales from the old app (plan section 5.4).
 * Safe to run again; the old app's data wins.
 *
 * - inventory_history_daily and sales_history_daily: upserted on
 *   (product_title, day). IDs are this app's own; nothing points at them
 * - variant_availability_intervals and lost_sales_estimates: an exact copy,
 *   IDs included (estimates point at intervals), via TableMirror. Only the
 *   by-hand stockout:derive-intervals and stockout:estimate-lost-sales write
 *   them, and neither is run here before cutover
 * - inventory_snapshots is not copied (spec 14.1): this app's own sweep
 *   fills it, and the 90-day clean-up keeps it small
 *
 * Reports counts only.
 */
final class LostSalesImporter
{
    private const CHUNK = 1000;

    public const HISTORY = [
        'inventory_history_daily' => ['product_title', 'day', 'ending_inventory_units', 'ingested_at', 'created_at', 'updated_at'],
        'sales_history_daily' => ['product_title', 'day', 'net_items_sold', 'net_sales', 'ingested_at', 'created_at', 'updated_at'],
    ];

    public const MIRRORED = [
        'variant_availability_intervals' => ['id', 'variant_id', 'sku', 'product_title', 'started_at', 'ended_at', 'source', 'confidence', 'notes', 'created_at', 'updated_at'],
        'lost_sales_estimates' => ['id', 'variant_availability_interval_id', 'variant_id', 'sku', 'product_title', 'started_at', 'ended_at', 'days_out_of_stock', 'baseline_units_per_day', 'baseline_revenue_per_day', 'baseline_window_days_used', 'baseline_confidence', 'substitution_note', 'gross_units_lost', 'gross_revenue_lost', 'model_version', 'computed_at', 'created_at', 'updated_at'],
    ];

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
    public function run(bool $dryRun = false): array
    {
        $report = [];
        $this->db->beginTransaction();

        try {
            foreach (self::HISTORY as $table => $columns) {
                $report[$table] = $this->upsertHistory($table, $columns);
            }
            $report += (new TableMirror($this->legacy, $this->db, $this->pauseMilliseconds))->mirror(self::MIRRORED);
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        $dryRun ? $this->db->rollBack() : $this->db->commit();

        return $report;
    }

    /**
     * @param  list<string>  $columns
     * @return array{read: int, new: int, updated: int, removed: int}
     */
    private function upsertHistory(string $table, array $columns): array
    {
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
        $key = fn ($row) => $row->product_title."\0".substr((string) $row->day, 0, 10);
        $first = true;

        $this->legacy->table($table)->select(['id', ...$columns])->orderBy('id')->chunk(self::CHUNK, function (Collection $rows) use ($table, $columns, $key, &$report, &$first) {
            if (! $first && $this->pauseMilliseconds > 0) {
                usleep($this->pauseMilliseconds * 1000);
            }
            $first = false;

            $here = $this->db->table($table)->whereIn('product_title', $rows->pluck('product_title')->unique()->values())
                ->whereBetween('day', [$rows->min('day'), $rows->max('day')])
                ->get(['product_title', 'day'])->map($key)->flip();
            $existing = $rows->filter(fn ($row) => isset($here[$key($row)]))->count();

            $this->db->table($table)->upsert(
                $rows->map(fn ($row) => array_intersect_key((array) $row, array_flip($columns)))->all(),
                ['product_title', 'day'],
                array_values(array_diff($columns, ['product_title', 'day', 'created_at'])),
            );

            $report['read'] += $rows->count();
            $report['updated'] += $existing;
            $report['new'] += $rows->count() - $existing;
        });

        return $report;
    }
}
