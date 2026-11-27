<?php

namespace App\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * `compare:asp-tracker`: compares the sales lines, their discounts and the
 * rebuilt daily rollups for orders up to a cutoff (by default 3 days ago;
 * rollups for whole days, a day's margin before it),
 * so orders the old app has received since the import don't count as
 * differences. Order IDs grow with time, so the cutoff is the old app's
 * newest order ID before that moment. Rows are hashed as a set
 * (MultisetHash): IDs differ between the apps for discounts and rollups.
 * Counts and hashes only.
 */
final class AspTrackerComparison
{
    private const ROLLUP_SALES = ['store', 'order_date', 'week_start', 'product_id', 'variant_id', 'is_subscription', 'is_free_item', 'is_excluded', 'app_name', 'line_count', 'gross_qty', 'net_qty', 'net_revenue', 'list_total', 'line_discount', 'order_discount', 'kept_order_discount', 'selling_plan_discount', 'full_price_qty', 'latest_line_item_id'];

    private const ROLLUP_DISCOUNTS = ['store', 'order_date', 'week_start', 'product_id', 'variant_id', 'is_subscription', 'is_free_item', 'is_excluded', 'app_name', 'label_group', 'amount'];

    /**
     * @return array{cutoff: string, sections: list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>}
     */
    public function run(\DateTimeInterface $before): array
    {
        $cutoffId = (int) DB::connection('legacy')->table('asp_sales_lines')->where('order_created_at', '<', $before->format('Y-m-d H:i:s'))->max('order_id');
        // Whole days before the cutoff, with a day's margin for the shop's time zone.
        $cutoffDate = (new \DateTimeImmutable($before->format('Y-m-d')))->modify('-1 day')->format('Y-m-d');

        $sections = [
            'Sales lines' => fn (?string $c) => $this->hash($c, 'asp_sales_lines', array_values(array_diff(AspTrackerImporter::LINE_COLUMNS, ['created_at', 'updated_at'])), 'line_item_id', fn ($q) => $q->where('order_id', '<=', $cutoffId)),
            'Line discounts' => fn (?string $c) => $this->hash($c, 'asp_line_discounts', AspTrackerImporter::DISCOUNT_COLUMNS, 'id', fn ($q) => $q->where('order_id', '<=', $cutoffId)),
            'Daily sales (rebuilt here)' => fn (?string $c) => $this->hash($c, 'asp_daily_sales', self::ROLLUP_SALES, 'id', fn ($q) => $q->where('order_date', '<', $cutoffDate)),
            'Daily discounts (rebuilt here)' => fn (?string $c) => $this->hash($c, 'asp_daily_discounts', self::ROLLUP_DISCOUNTS, 'id', fn ($q) => $q->where('order_date', '<', $cutoffDate)),
        ];

        $out = [];
        foreach ($sections as $name => $hash) {
            $old = $hash('legacy');
            $new = $hash(null);
            $out[] = ['section' => $name, 'old_rows' => $old->count(), 'new_rows' => $new->count(), 'old_hash' => $old->digest(), 'new_hash' => $new->digest(), 'match' => $old->count() === $new->count() && $old->digest() === $new->digest()];
        }

        return ['cutoff' => $before->format('Y-m-d H:i'), 'sections' => $out];
    }

    /** @param  list<string>  $columns */
    private function hash(?string $connection, string $table, array $columns, string $key, callable $scope): MultisetHash
    {
        $hash = new MultisetHash;
        $query = DB::connection($connection)->table($table)->select(array_unique([$key, ...$columns]));
        $scope($query);
        foreach ($query->lazyById(5000, $key) as $row) {
            $row = (array) $row;
            if (! in_array($key, $columns, true)) {
                unset($row[$key]);
            }
            $hash->add($row);
        }

        return $hash;
    }
}
