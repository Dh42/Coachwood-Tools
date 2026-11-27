<?php

namespace App\Services\AspTracker;

use App\Models\AspIngestLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every number on the ASP Tracker page. Aggregates come from the daily
 * rollups (asp_daily_sales / asp_daily_discounts, see DailyRollup) — at full
 * history the same GROUP BYs over raw asp_sales_lines took ~44s a page. Only
 * the drill-down reads raw lines, and only for the one period clicked.
 * Product-level figures are SUMs over the product's lines,
 * i.e. quantity-weighted across variants, never a mean of variant ASPs.
 *
 *   Net quantity   SUM(quantity − refunded_qty)
 *   Net revenue    SUM(net_line_total − refunded_amount)   CAD, pre-tax, pre-shipping
 *   ASP            net revenue ÷ net quantity
 *   List ASP       SUM(list price × quantity) ÷ SUM(quantity) — list includes
 *                  the derived pre-subscription price (ListPriceResolver)
 *   Product ASP    (net revenue + order-level discount on kept units) ÷ net
 *                  quantity — ASP before Rivo / order codes, i.e. what the
 *                  product's own pricing (sale, subscription) netted
 *   Discount depth 1 − ASP ÷ list ASP
 *   Full-price     net units with no line, order or selling-plan discount ÷ net quantity
 *
 * Always excluded: cancelled orders. Refunds stay on the original order's
 * date. Free lines (net $0) are left out unless include_free is on, and
 * counted separately. The store's default_exclusions (draft orders; for
 * BioSteel also Influencer Hero and marketplaces) are left out of the
 * default channel view (user decisions 2026-10-19/20) but selectable.
 *
 * $f is the filter array built by normalizeFilters().
 */
class AspReportService
{
    public const GRANULARITIES = ['day', 'week', 'month'];

    public const ORDER_TYPES = ['all', 'one_time', 'subscription'];

    public const CHANNEL_RETAIL = '__retail';

    public const CHANNEL_ALL = '__all';

    public const SUBSCRIPTION_LABEL = 'Subscription discount (selling plan)';

    public static function normalizeFilters(array $input, string $store): array
    {
        $timezone = config("asp_tracker.stores.{$store}.timezone", 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $parse = function ($value, CarbonImmutable $fallback) {
            try {
                return filled($value) ? CarbonImmutable::createFromFormat('Y-m-d', (string) $value)->startOfDay() : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $from = $parse($input['from'] ?? null, $today->subMonthsNoOverflow(12)->addDay());
        $to = $parse($input['to'] ?? null, $today);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $granularity = in_array($input['granularity'] ?? null, self::GRANULARITIES, true) ? $input['granularity'] : 'week';
        $orderType = in_array($input['order_type'] ?? null, self::ORDER_TYPES, true) ? $input['order_type'] : 'all';

        return [
            'store' => $store,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'granularity' => $granularity,
            'product_id' => is_numeric($input['product_id'] ?? null) ? (int) $input['product_id'] : null,
            'variant_id' => is_numeric($input['variant_id'] ?? null) ? (int) $input['variant_id'] : null,
            'order_type' => $orderType,
            'channel' => filled($input['channel'] ?? null) ? (string) $input['channel'] : self::CHANNEL_RETAIL,
            'include_free' => filter_var($input['include_free'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** Filtered daily-rollup rows (asp_daily_sales, or asp_daily_discounts). $free: null = honour include_free, true/false = force. */
    public function rollup(array $f, ?bool $free = null, string $table = 'asp_daily_sales'): Builder
    {
        $q = $this->filtered(DB::table("{$table} as l"), $f, $free);

        if ($f['channel'] === self::CHANNEL_RETAIL) {
            $q->where('l.is_excluded', false);
        }

        return $q;
    }

    /** Filtered raw lines — the drill-down only; everything else reads rollup(). */
    public function lines(array $f, ?bool $free = null): Builder
    {
        $q = $this->filtered(DB::table('asp_sales_lines as l')->whereNull('l.cancelled_at'), $f, $free);

        if ($f['channel'] === self::CHANNEL_RETAIL) {
            $q->whereRaw('('.DailyRollup::exclusionSql($f['store']).') = 0');
        }

        return $q;
    }

    /** The filters shared by raw lines and rollup rows (same column names), bar the default-view exclusions. */
    private function filtered(Builder $q, array $f, ?bool $free): Builder
    {
        $q->where('l.store', $f['store'])
            ->whereBetween('l.order_date', [$f['from'], $f['to']]);

        if ($f['product_id'] !== null) {
            $q->where('l.product_id', $f['product_id']);
        }
        if ($f['variant_id'] !== null) {
            $q->where('l.variant_id', $f['variant_id']);
        }
        if ($f['order_type'] !== 'all') {
            $q->where('l.is_subscription', $f['order_type'] === 'subscription');
        }

        if (! in_array($f['channel'], [self::CHANNEL_RETAIL, self::CHANNEL_ALL], true)) {
            $q->where('l.app_name', $f['channel']);
        }

        if ($free === true) {
            $q->where('l.is_free_item', true);
        } elseif ($free === false || ! $f['include_free']) {
            $q->where('l.is_free_item', false);
        }

        return $q;
    }

    public static function periodExpression(string $granularity): string
    {
        return match ($granularity) {
            'day' => 'l.order_date',
            'week' => 'l.week_start',
            'month' => 'substr(l.order_date, 1, 7)',
        };
    }

    /** Over rollup() rows — each column is already a per-day SUM (see DailyRollup). */
    private static function metricColumns(): array
    {
        return array_map(fn ($c) => DB::raw("SUM(l.{$c}) as {$c}"), [
            'net_qty', 'net_revenue', 'gross_qty', 'list_total', 'line_discount', 'order_discount', 'kept_order_discount', 'selling_plan_discount', 'full_price_qty',
        ]);
    }

    /** Raw SUMs → the page's metrics. */
    public static function derive(?object $row): array
    {
        $netQty = (int) ($row->net_qty ?? 0);
        $grossQty = (int) ($row->gross_qty ?? 0);
        $netRevenue = (float) ($row->net_revenue ?? 0);
        $listTotal = (float) ($row->list_total ?? 0);
        $line = (float) ($row->line_discount ?? 0);
        $order = (float) ($row->order_discount ?? 0);
        $plan = (float) ($row->selling_plan_discount ?? 0);
        $discountTotal = $line + $order + $plan;
        $keptOrder = (float) ($row->kept_order_discount ?? 0);

        $asp = $netQty > 0 ? $netRevenue / $netQty : null;
        $listAsp = $grossQty > 0 ? $listTotal / $grossQty : null;

        return [
            'net_qty' => $netQty,
            'gross_qty' => $grossQty,
            'refunded_qty' => $grossQty - $netQty,
            'net_revenue' => round($netRevenue, 2),
            'asp' => $asp,
            'product_asp' => $netQty > 0 ? ($netRevenue + $keptOrder) / $netQty : null,
            'list_asp' => $listAsp,
            'discount_depth' => $asp !== null && $listAsp ? 1 - $asp / $listAsp : null,
            'full_price_share' => $netQty > 0 ? (int) ($row->full_price_qty ?? 0) / $netQty : null,
            'line_discount' => round($line, 2),
            'order_discount' => round($order, 2),
            'selling_plan_discount' => round($plan, 2),
            'order_discount_share' => $discountTotal > 0 ? $order / $discountTotal : null,
            'line_discount_share' => $discountTotal > 0 ? $line / $discountTotal : null,
            'selling_plan_discount_share' => $discountTotal > 0 ? $plan / $discountTotal : null,
        ];
    }

    public function summary(array $f): array
    {
        $metrics = self::derive($this->rollup($f)->select(self::metricColumns())->first());
        $metrics['free_qty'] = (int) $this->rollup($f, free: true)->sum('l.net_qty');

        return $metrics;
    }

    /** One entry per period in range (empty periods included, so the chart's x-axis is continuous). */
    public function periods(array $f): array
    {
        $expr = self::periodExpression($f['granularity']);

        $rows = $this->rollup($f)
            ->select([DB::raw("{$expr} as period"), ...self::metricColumns()])
            ->groupBy(DB::raw($expr))
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->period, 0, 10));

        $free = $this->rollup($f, free: true)
            ->select([DB::raw("{$expr} as period"), DB::raw('SUM(l.net_qty) as qty')])
            ->groupBy(DB::raw($expr))
            ->pluck('qty', 'period')
            ->mapWithKeys(fn ($qty, $period) => [substr((string) $period, 0, 10) => (int) $qty]);

        $topLabels = $this->topLabelsByPeriod($f, $expr);

        $periods = [];
        foreach ($this->periodKeys($f) as $key) {
            $periods[] = ['period' => $key, 'label' => self::periodLabel($key, $f['granularity'])]
                + self::derive($rows[$key] ?? null)
                + ['free_qty' => $free[$key] ?? 0, 'top_labels' => $topLabels[$key] ?? []];
        }

        return $periods;
    }

    /** @return array<string, list<array{label: string, amount: float}>> top 3 discount groups per period, incl. the selling-plan discount */
    private function topLabelsByPeriod(array $f, string $expr): array
    {
        $rows = $this->rollup($f, table: 'asp_daily_discounts')
            ->select([DB::raw("{$expr} as period"), 'l.label_group', DB::raw('SUM(l.amount) as amount')])
            ->groupBy(DB::raw($expr), 'l.label_group')
            ->get();

        $byPeriod = [];
        foreach ($rows as $row) {
            $byPeriod[substr((string) $row->period, 0, 10)][] = ['label' => $row->label_group, 'amount' => (float) $row->amount];
        }

        $plan = $this->rollup($f)
            ->where('l.selling_plan_discount', '>', 0)
            ->select([DB::raw("{$expr} as period"), DB::raw('SUM(l.selling_plan_discount) as amount')])
            ->groupBy(DB::raw($expr))
            ->get();
        foreach ($plan as $row) {
            $byPeriod[substr((string) $row->period, 0, 10)][] = ['label' => self::SUBSCRIPTION_LABEL, 'amount' => (float) $row->amount];
        }

        foreach ($byPeriod as $key => $labels) {
            usort($labels, fn ($a, $b) => $b['amount'] <=> $a['amount']);
            $byPeriod[$key] = array_slice($labels, 0, 3);
        }

        return $byPeriod;
    }

    /** One row per variant (or product) over the whole range, biggest net revenue first. */
    public function breakdown(array $f, string $groupBy): array
    {
        $column = $groupBy === 'product' ? 'l.product_id' : 'l.variant_id';

        $rows = $this->rollup($f)
            ->select([DB::raw("{$column} as group_id"), DB::raw('MAX(l.product_id) as product_id'), DB::raw('MAX(l.latest_line_item_id) as latest_line'), ...self::metricColumns()])
            ->groupBy(DB::raw($column))
            ->orderByDesc('net_revenue')
            ->limit(1000)
            ->get();

        // Titles are snapshots at order time — show the latest one seen.
        $titles = DB::table('asp_sales_lines')
            ->whereIn('line_item_id', $rows->pluck('latest_line')->all())
            ->get(['line_item_id', 'product_title', 'variant_title', 'sku'])
            ->keyBy('line_item_id');

        return $rows->map(function ($row) use ($titles, $groupBy) {
            $latest = $titles[$row->latest_line] ?? null;

            return [
                'id' => $row->group_id,
                'product_id' => $row->product_id,
                'product_title' => $latest->product_title ?? '(deleted product)',
                'variant_title' => $groupBy === 'product' ? null : ($latest->variant_title ?? null),
                'sku' => $groupBy === 'product' ? null : ($latest->sku ?? null),
            ] + self::derive($row);
        })->all();
    }

    /** Discount groups active in one period: lines, units and dollars per group, so an ASP dip can be traced to its campaign. */
    public function drilldown(array $f, string $period): array
    {
        // As a date range rather than "period expression = ?", so the
        // (store, order_date) indexes narrow the scan to just that period.
        $start = CarbonImmutable::parse(strlen($period) === 7 ? "{$period}-01" : $period);
        $end = match ($f['granularity']) {
            'day' => $start,
            'week' => $start->addDays(6),
            'month' => $start->endOfMonth(),
        };
        $range = [$start->toDateString(), $end->toDateString()];
        $scoped = fn (?bool $free = null) => $this->lines($f, $free)->whereBetween('l.order_date', $range);

        // Per line first, so a line carrying two codes from the same group
        // (seen live: two Rivo codes on one order) counts its units once.
        $perLine = $scoped()
            ->join('asp_line_discounts as d', 'd.line_item_id', '=', 'l.line_item_id')
            ->select([
                'd.label_group', 'd.line_item_id',
                DB::raw('SUM(d.amount) as amount'),
                DB::raw('MAX(l.quantity - l.refunded_qty) as units'),
                DB::raw('MAX(d.level) as level'),
                DB::raw('MAX(d.discount_type) as discount_type'),
                DB::raw('COUNT(DISTINCT d.label) as labels'),
            ])
            ->groupBy('d.label_group', 'd.line_item_id')
            ->get();

        $groups = [];
        foreach ($perLine as $row) {
            $g = &$groups[$row->label_group];
            $g['label'] = $row->label_group;
            $g['level'] = $row->level;
            $g['discount_type'] = $row->discount_type;
            $g['lines'] = ($g['lines'] ?? 0) + 1;
            $g['units'] = ($g['units'] ?? 0) + (int) $row->units;
            $g['amount'] = ($g['amount'] ?? 0) + (float) $row->amount;
            unset($g);
        }

        $codeCounts = $scoped()
            ->join('asp_line_discounts as d', 'd.line_item_id', '=', 'l.line_item_id')
            ->select(['d.label_group', DB::raw('COUNT(DISTINCT d.label) as codes')])
            ->groupBy('d.label_group')
            ->pluck('codes', 'label_group');
        foreach ($groups as $label => $group) {
            $groups[$label]['codes'] = (int) ($codeCounts[$label] ?? 1);
        }

        $plan = $scoped()->where('l.selling_plan_discount', '>', 0)
            ->select([DB::raw('COUNT(*) as line_count'), DB::raw('SUM(l.quantity - l.refunded_qty) as units'), DB::raw('SUM(l.selling_plan_discount) as amount')])
            ->first();
        if ($plan && (int) $plan->line_count > 0) {
            $groups[self::SUBSCRIPTION_LABEL] = ['label' => self::SUBSCRIPTION_LABEL, 'level' => 'plan', 'discount_type' => 'SELLING_PLAN', 'lines' => (int) $plan->line_count, 'units' => (int) $plan->units, 'amount' => (float) $plan->amount, 'codes' => 1];
        }

        usort($groups, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        $summary = self::derive($this->rollup($f)->whereBetween('l.order_date', $range)->select(self::metricColumns())->first());

        return [
            'period' => $period,
            'label' => self::periodLabel($period, $f['granularity']),
            'summary' => $summary,
            'groups' => array_map(fn ($g) => [...$g, 'amount' => round($g['amount'], 2)], array_values($groups)),
        ];
    }

    /** @return list<array{id: int, title: string}> */
    public function products(string $store): array
    {
        return $this->latestTitles($store, 'product_id', null);
    }

    /** @return list<array{id: int, title: string}> */
    public function variants(string $store, int $productId): array
    {
        return $this->latestTitles($store, 'variant_id', $productId);
    }

    private function latestTitles(string $store, string $column, ?int $productId): array
    {
        $latest = DB::table('asp_daily_sales')
            ->where('store', $store)
            ->whereNotNull($column)
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->groupBy($column)
            ->selectRaw('MAX(latest_line_item_id) as latest_line')
            ->pluck('latest_line');

        return DB::table('asp_sales_lines')
            ->whereIn('line_item_id', $latest->all())
            ->get([$column, 'product_title', 'variant_title', 'sku'])
            ->map(fn ($r) => [
                'id' => (int) $r->{$column},
                'title' => $column === 'product_id'
                    ? (string) $r->product_title
                    : trim(($r->variant_title ?: 'Default').($r->sku ? " ({$r->sku})" : '')),
            ])
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function channels(string $store): array
    {
        return DB::table('asp_daily_sales')->where('store', $store)->whereNotNull('app_name')
            ->distinct()->orderBy('app_name')->pluck('app_name')->all();
    }

    public function ingestStatus(string $store): array
    {
        return [
            'backfill' => BackfillService::latest($store),
            'last_pending' => AspIngestLog::where('store', $store)->where('kind', 'pending')->orderByDesc('id')->first(),
            'last_catch_up' => AspIngestLog::where('store', $store)->where('kind', 'catch-up')->orderByDesc('id')->first(),
            'queued' => DB::table('asp_pending_orders')->where('store', $store)->where('attempts', '<', PendingOrderProcessor::MAX_ATTEMPTS)->count(),
            'parked' => DB::table('asp_pending_orders')->where('store', $store)->where('attempts', '>=', PendingOrderProcessor::MAX_ATTEMPTS)->count(),
            'lines' => (int) DB::table('asp_daily_sales')->where('store', $store)->sum('line_count'),
            'unresolved_list_prices' => DB::table('asp_sales_lines')->where('store', $store)->where('is_subscription', true)->whereNull('list_price_source')->count(),
            'recent' => AspIngestLog::where('store', $store)->orderByDesc('id')->limit(10)->get(),
        ];
    }

    /** @return list<string> */
    private function periodKeys(array $f): array
    {
        $from = CarbonImmutable::parse($f['from']);
        $to = CarbonImmutable::parse($f['to']);

        [$start, $step, $format] = match ($f['granularity']) {
            'day' => [$from, '1 day', 'Y-m-d'],
            'week' => [$from->startOfWeek(CarbonImmutable::MONDAY), '1 week', 'Y-m-d'],
            'month' => [$from->startOfMonth(), '1 month', 'Y-m'],
        };

        $keys = [];
        foreach (CarbonPeriod::create($start, $step, $to) as $date) {
            $keys[] = $date->format($format);
        }

        return $keys;
    }

    public static function periodLabel(string $key, string $granularity): string
    {
        return match ($granularity) {
            'day' => CarbonImmutable::parse($key)->format('M j, Y'),
            'week' => 'Week of '.CarbonImmutable::parse($key)->format('M j, Y'),
            'month' => CarbonImmutable::parse($key.'-01')->format('M Y'),
        };
    }
}
