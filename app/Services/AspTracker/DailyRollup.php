<?php

namespace App\Services\AspTracker;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Maintains asp_daily_sales / asp_daily_discounts, the pre-summed tables
 * the ASP Tracker page reads. One row per store × day × product × variant ×
 * subscription × free × excluded × app — every filter the page offers, so
 * any filter combination is still an exact SUM. "Excluded" is the store's
 * default_exclusions (drafts; for BioSteel also Influencer Hero and
 * marketplace orders), worked out at build time.
 *
 * A day is always rebuilt whole (delete + INSERT … SELECT in one
 * transaction), so there's no incremental arithmetic to drift. Days are
 * rebuilt by SalesLineWriter and ListPriceResolver whenever they change a
 * line; asp:rebuild-rollups rebuilds everything nightly as the safety net
 * (and once after the rollups are first deployed).
 */
class DailyRollup
{
    private const DATE_CHUNK = 100;

    /**
     * Every ASP write transaction retries this many times on a deadlock.
     * The backfill import, the per-minute pending queue and the nightly jobs
     * can all rebuild rollup days at once; the DELETE + INSERT … SELECT here
     * takes gap locks, so two of them can deadlock even on different days
     * (seen live on the BioSteel backfill). MariaDB rolls back one side and
     * a retry goes through. Nested inside a caller's transaction, a deadlock
     * is rethrown and retried by that outermost transaction.
     */
    public const DEADLOCK_ATTEMPTS = 5;

    private const DIMENSIONS = ['store', 'order_date', 'week_start', 'product_id', 'variant_id', 'is_subscription', 'is_free_item', 'is_excluded', 'app_name'];

    /**
     * SQL that is 1 for a line (alias l) the store's default view leaves out,
     * else 0. NULL source/app names fall through to 0 (kept).
     */
    public static function exclusionSql(string $store): string
    {
        $rules = config("asp_tracker.stores.{$store}.default_exclusions", []);
        $pdo = DB::connection()->getPdo();
        $in = fn (array $values) => implode(', ', array_map(fn ($v) => $pdo->quote((string) $v), $values));

        $conditions = [];
        if (! empty($rules['source_names'])) {
            $conditions[] = "l.source_name IN ({$in($rules['source_names'])})";
        }
        if (! empty($rules['app_names'])) {
            $conditions[] = "l.app_name IN ({$in($rules['app_names'])})";
        }

        return $conditions === [] ? '0' : 'CASE WHEN '.implode(' OR ', $conditions).' THEN 1 ELSE 0 END';
    }

    /** @param  iterable<string>  $dates  Y-m-d shop-local order dates */
    public function rebuildDates(string $store, iterable $dates): void
    {
        $dates = array_values(array_unique(array_map(fn ($d) => substr((string) $d, 0, 10), [...$dates])));
        sort($dates);

        foreach (array_chunk($dates, self::DATE_CHUNK) as $chunk) {
            $this->rebuild($store, fn ($q, string $column) => $q->whereIn($column, $chunk));
        }
    }

    /** Every day the store has lines for, a month per transaction. @return int months rebuilt */
    public function rebuildAll(string $store): int
    {
        $first = DB::table('asp_sales_lines')->where('store', $store)->min('order_date');
        $last = DB::table('asp_sales_lines')->where('store', $store)->max('order_date');

        // Rows for days outside the lines' range (nothing left to rebuild them from).
        foreach (['asp_daily_sales', 'asp_daily_discounts'] as $table) {
            DB::table($table)->where('store', $store)
                ->when($first !== null, fn ($q) => $q->where(fn ($w) => $w->where('order_date', '<', $first)->orWhere('order_date', '>', $last)))
                ->delete();
        }

        if ($first === null) {
            return 0;
        }

        $months = 0;
        for ($month = CarbonImmutable::parse($first)->startOfMonth(); $month->lte(CarbonImmutable::parse($last)); $month = $month->addMonthNoOverflow()) {
            $range = [$month->toDateString(), $month->endOfMonth()->toDateString()];
            $this->rebuild($store, fn ($q, string $column) => $q->whereBetween($column, $range));
            $months++;
        }

        return $months;
    }

    /** @param  callable(Builder, string): Builder  $scope  limits a query to the days being rebuilt */
    private function rebuild(string $store, callable $scope): void
    {
        $excluded = self::exclusionSql($store);
        // Grouped by the alias, not the expression: MariaDB's ONLY_FULL_GROUP_BY
        // doesn't match an IN (...) expression in GROUP BY to the same one in
        // SELECT (1055 "source_name isn't in GROUP BY", seen live). SQLite
        // accepts the alias too.
        $groupBy = ['l.store', 'l.order_date', 'l.week_start', 'l.product_id', 'l.variant_id', 'l.is_subscription', 'l.is_free_item', 'is_excluded', 'l.app_name'];
        $dimensions = ['l.store', 'l.order_date', 'l.week_start', 'l.product_id', 'l.variant_id', 'l.is_subscription', 'l.is_free_item', DB::raw("{$excluded} as is_excluded"), 'l.app_name'];

        $lines = fn () => $scope(DB::table('asp_sales_lines as l')->where('l.store', $store)->whereNull('l.cancelled_at'), 'l.order_date');

        $sales = $lines()->select([
            ...$dimensions,
            DB::raw('COUNT(*)'),
            DB::raw('SUM(l.quantity)'),
            DB::raw('SUM(l.quantity - l.refunded_qty)'),
            DB::raw('SUM(l.net_line_total - l.refunded_amount)'),
            DB::raw('SUM(COALESCE(l.list_unit_price, l.original_unit_price) * l.quantity)'),
            DB::raw('SUM(l.line_level_discount)'),
            DB::raw('SUM(l.order_level_discount)'),
            DB::raw('SUM(CASE WHEN l.quantity > 0 THEN l.order_level_discount * (l.quantity - l.refunded_qty) / l.quantity ELSE 0 END)'),
            DB::raw('SUM(l.selling_plan_discount)'),
            DB::raw('SUM(CASE WHEN l.line_level_discount = 0 AND l.order_level_discount = 0 AND l.selling_plan_discount = 0 THEN l.quantity - l.refunded_qty ELSE 0 END)'),
            DB::raw('MAX(l.line_item_id)'),
        ])->groupBy($groupBy);

        $discounts = $lines()
            ->join('asp_line_discounts as d', 'd.line_item_id', '=', 'l.line_item_id')
            ->select([...$dimensions, 'd.label_group', DB::raw('SUM(d.amount)')])
            ->groupBy([...$groupBy, 'd.label_group']);

        DB::transaction(function () use ($store, $scope, $sales, $discounts) {
            $scope(DB::table('asp_daily_sales')->where('store', $store), 'order_date')->delete();
            $scope(DB::table('asp_daily_discounts')->where('store', $store), 'order_date')->delete();

            DB::table('asp_daily_sales')->insertUsing([
                ...self::DIMENSIONS, 'line_count', 'gross_qty', 'net_qty', 'net_revenue', 'list_total',
                'line_discount', 'order_discount', 'kept_order_discount', 'selling_plan_discount', 'full_price_qty', 'latest_line_item_id',
            ], $sales);
            DB::table('asp_daily_discounts')->insertUsing([...self::DIMENSIONS, 'label_group', 'amount'], $discounts);
        }, self::DEADLOCK_ATTEMPTS);
    }
}
