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
 *
 * Both apps keep saving orders while they run side by side (webhooks, the
 * order queue), so when lines or discounts differ, the differing orders are
 * found (rows are also summed per group of orders, by order ID modulo
 * BUCKETS, and only differing groups are read again) and sorted by what the
 * save times say happened. Counts, hashes and save times only.
 */
final class AspTrackerComparison
{
    private const ROLLUP_SALES = ['store', 'order_date', 'week_start', 'product_id', 'variant_id', 'is_subscription', 'is_free_item', 'is_excluded', 'app_name', 'line_count', 'gross_qty', 'net_qty', 'net_revenue', 'list_total', 'line_discount', 'order_discount', 'kept_order_discount', 'selling_plan_discount', 'full_price_qty', 'latest_line_item_id'];

    private const ROLLUP_DISCOUNTS = ['store', 'order_date', 'week_start', 'product_id', 'variant_id', 'is_subscription', 'is_free_item', 'is_excluded', 'app_name', 'label_group', 'amount'];

    /**
     * A prime: Shopify order IDs are all multiples of 2,048, so a power of
     * two put every order in one or two groups (found on the real data).
     */
    private const BUCKETS = 4093;

    /** Differing groups read again; past this the breakdown covers a sample. */
    private const MAX_BUCKETS = 64;

    public const OLD_ONLY = 'Only in the old app';

    public const HERE_ONLY = 'Only here';

    public const SAVED_IN_OLD_LATER = 'Saved in the old app after this copy';

    public const SAVED_HERE_LATER = 'Saved here after the old app';

    public const SAME_SAVE_TIME = 'Different, same save time';

    /** @var array<string, array<string, array<int, int>>> section => connection => bucket => sum */
    private array $buckets = [];

    /**
     * @return array{cutoff: string, sections: list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>, orders: list<array{what: string, orders: int, old_lines: int, new_lines: int, old_saved: ?string, new_saved: ?string}>, sampled: bool}
     */
    public function run(\DateTimeInterface $before): array
    {
        $this->buckets = [];
        $cutoffId = (int) DB::connection('legacy')->table('asp_sales_lines')->where('order_created_at', '<', $before->format('Y-m-d H:i:s'))->max('order_id');
        // Whole days before the cutoff, with a day's margin for the shop's time zone.
        $cutoffDate = (new \DateTimeImmutable($before->format('Y-m-d')))->modify('-1 day')->format('Y-m-d');
        $byOrder = fn ($q) => $q->where('order_id', '<=', $cutoffId);

        $sections = [
            'Sales lines' => fn (?string $c) => $this->hash($c, 'asp_sales_lines', self::lineColumns(), 'line_item_id', $byOrder, 'lines'),
            'Line discounts' => fn (?string $c) => $this->hash($c, 'asp_line_discounts', AspTrackerImporter::DISCOUNT_COLUMNS, 'id', $byOrder, 'discounts'),
            'Daily sales (rebuilt here)' => fn (?string $c) => $this->hash($c, 'asp_daily_sales', self::ROLLUP_SALES, 'id', fn ($q) => $q->where('order_date', '<', $cutoffDate)),
            'Daily discounts (rebuilt here)' => fn (?string $c) => $this->hash($c, 'asp_daily_discounts', self::ROLLUP_DISCOUNTS, 'id', fn ($q) => $q->where('order_date', '<', $cutoffDate)),
        ];

        $out = [];
        foreach ($sections as $name => $hash) {
            $old = $hash('legacy');
            $new = $hash(null);
            $out[] = ['section' => $name, 'old_rows' => $old->count(), 'new_rows' => $new->count(), 'old_hash' => $old->digest(), 'new_hash' => $new->digest(), 'match' => $old->count() === $new->count() && $old->digest() === $new->digest()];
        }

        $differing = [];
        foreach ($this->buckets as $sides) {
            foreach (array_keys(($sides['legacy'] ?? []) + ($sides[''] ?? [])) as $bucket) {
                if (($sides['legacy'][$bucket] ?? 0) !== ($sides[''][$bucket] ?? 0)) {
                    $differing[$bucket] = true;
                }
            }
        }
        ksort($differing);
        $sampled = count($differing) > self::MAX_BUCKETS;
        $differing = array_slice(array_keys($differing), 0, self::MAX_BUCKETS);

        return ['cutoff' => $before->format('Y-m-d H:i'), 'sections' => $out, 'orders' => $differing === [] ? [] : $this->differingOrders($cutoffId, $differing), 'sampled' => $sampled];
    }

    /** @return list<string> */
    private static function lineColumns(): array
    {
        return array_values(array_diff(AspTrackerImporter::LINE_COLUMNS, ['created_at', 'updated_at']));
    }

    /** @param  list<string>  $columns */
    private function hash(?string $connection, string $table, array $columns, string $key, callable $scope, ?string $bucketed = null): MultisetHash
    {
        $hash = new MultisetHash;
        $sums = [];
        $query = DB::connection($connection)->table($table)->select(array_unique([$key, ...$columns]));
        $scope($query);
        foreach ($query->lazyById(5000, $key) as $row) {
            $row = (array) $row;
            if (! in_array($key, $columns, true)) {
                unset($row[$key]);
            }
            $rowHash = MultisetHash::rowHash($row);
            $hash->addHash($rowHash);
            if ($bucketed !== null) {
                $bucket = (int) $row['order_id'] % self::BUCKETS;
                $sums[$bucket] = MultisetHash::combine($sums[$bucket] ?? 0, $rowHash);
            }
        }
        if ($bucketed !== null) {
            $this->buckets[$bucketed][$connection ?? ''] = $sums;
        }

        return $hash;
    }

    /**
     * Reads the differing groups again on both sides, keeps the orders whose
     * lines or discounts differ, and counts them by what their newest line
     * save time (updated_at, which the import copies) says.
     *
     * @param  list<int>  $buckets
     * @return list<array{what: string, orders: int, old_lines: int, new_lines: int, old_saved: ?string, new_saved: ?string}>
     */
    private function differingOrders(int $cutoffId, array $buckets): array
    {
        $old = $this->ordersIn('legacy', $cutoffId, $buckets);
        $new = $this->ordersIn(null, $cutoffId, $buckets);
        $none = ['lines' => 0, 'line_sum' => 0, 'discount_sum' => 0, 'saved' => null];

        $groups = [];
        foreach (array_keys($old + $new) as $orderId) {
            $o = $old[$orderId] ?? $none;
            $n = $new[$orderId] ?? $none;
            if ($o['line_sum'] === $n['line_sum'] && $o['discount_sum'] === $n['discount_sum'] && $o['lines'] === $n['lines']) {
                continue;
            }
            $what = match (true) {
                $n['lines'] === 0 => self::OLD_ONLY,
                $o['lines'] === 0 => self::HERE_ONLY,
                $o['saved'] > $n['saved'] => self::SAVED_IN_OLD_LATER,
                $n['saved'] > $o['saved'] => self::SAVED_HERE_LATER,
                default => self::SAME_SAVE_TIME,
            };
            $g = $groups[$what] ?? ['what' => $what, 'orders' => 0, 'old_lines' => 0, 'new_lines' => 0, 'old_saved' => null, 'new_saved' => null];
            $g['orders']++;
            $g['old_lines'] += $o['lines'];
            $g['new_lines'] += $n['lines'];
            $g['old_saved'] = max($g['old_saved'], $o['saved']);
            $g['new_saved'] = max($g['new_saved'], $n['saved']);
            $groups[$what] = $g;
        }

        $order = [self::OLD_ONLY, self::HERE_ONLY, self::SAVED_IN_OLD_LATER, self::SAVED_HERE_LATER, self::SAME_SAVE_TIME];

        return array_values(array_filter(array_map(fn (string $what) => $groups[$what] ?? null, $order)));
    }

    /**
     * @param  list<int>  $buckets
     * @return array<int, array{lines: int, line_sum: int, discount_sum: int, saved: ?string}>
     */
    private function ordersIn(?string $connection, int $cutoffId, array $buckets): array
    {
        $in = fn ($q) => $q->where('order_id', '<=', $cutoffId)
            ->whereRaw('(order_id % '.self::BUCKETS.') in ('.implode(',', array_map('intval', $buckets)).')');

        $orders = [];
        $lines = DB::connection($connection)->table('asp_sales_lines')->select(['line_item_id', ...self::lineColumns(), 'updated_at']);
        foreach ($in($lines)->lazyById(5000, 'line_item_id') as $row) {
            $row = (array) $row;
            $saved = $row['updated_at'] === null ? null : substr((string) $row['updated_at'], 0, 19);
            unset($row['updated_at']);
            $o = $orders[(int) $row['order_id']] ?? ['lines' => 0, 'line_sum' => 0, 'discount_sum' => 0, 'saved' => null];
            $o['lines']++;
            $o['line_sum'] = MultisetHash::combine($o['line_sum'], MultisetHash::rowHash($row));
            $o['saved'] = max($o['saved'], $saved);
            $orders[(int) $row['order_id']] = $o;
        }

        $discounts = DB::connection($connection)->table('asp_line_discounts')->select(['id', ...AspTrackerImporter::DISCOUNT_COLUMNS]);
        foreach ($in($discounts)->lazyById(5000, 'id') as $row) {
            $row = (array) $row;
            unset($row['id']);
            $o = $orders[(int) $row['order_id']] ?? ['lines' => 0, 'line_sum' => 0, 'discount_sum' => 0, 'saved' => null];
            $o['discount_sum'] = MultisetHash::combine($o['discount_sum'], MultisetHash::rowHash($row));
            $orders[(int) $row['order_id']] = $o;
        }

        return $orders;
    }
}
