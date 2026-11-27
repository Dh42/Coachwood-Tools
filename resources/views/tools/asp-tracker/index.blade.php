{{--
    ASP Tracker (Canadian Protein at /tools/asp-tracker, BioSteel at
    /tools/asp-tracker-bs), ported from the old app onto the new design.
    The charts, drill-down and product search are in
    resources/js/tools/asp-tracker.js; page data is in #asp-config.
--}}
@php
    use App\Services\AspTracker\AspReportService;

    $money = fn ($v) => $v === null ? '—' : '$'.number_format($v, 2);
    $pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 1).'%';
    $int = fn ($v) => number_format((int) $v);
    $backfill = $ingest['backfill'];
    $query = array_filter([
        'from' => $filters['from'], 'to' => $filters['to'], 'granularity' => $filters['granularity'],
        'product_id' => $filters['product_id'], 'variant_id' => $filters['variant_id'],
        'order_type' => $filters['order_type'] !== 'all' ? $filters['order_type'] : null,
        'channel' => $filters['channel'] !== AspReportService::CHANNEL_RETAIL ? $filters['channel'] : null,
        'include_free' => $filters['include_free'] ? 1 : null,
    ], fn ($v) => $v !== null);
    $granularityLabel = ['day' => __('Day'), 'week' => __('Week'), 'month' => __('Month')];
    $period = strtolower($granularityLabel[$filters['granularity']]);
    $tab = request('tab') === 'periods' ? 'periods' : 'breakdown';
    $storeConfig = config('asp_tracker.stores.'.$filters['store']);
    $today = \Carbon\CarbonImmutable::now($storeConfig['timezone']);
    $presets = [
        __('Last 3 months') => [$today->subMonthsNoOverflow(3)->addDay(), $today],
        __('Last 6 months') => [$today->subMonthsNoOverflow(6)->addDay(), $today],
        __('Last 12 months') => [$today->subMonthsNoOverflow(12)->addDay(), $today],
        __('This year') => [$today->startOfYear(), $today],
        __('Last year') => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
        __('All data') => [\Carbon\CarbonImmutable::parse($storeConfig['backfill_since']), $today],
    ];
    $tourSteps = [
        ['element' => '#asp-empty-state', 'intro' => __('Nothing to show until the order history has been imported.')],
        ['element' => '#asp-filters', 'intro' => __("Pick the date range and whether to group by day, week or month, then narrow to one product (type to search) and optionally one variant. Order type splits subscriptions from one-time orders. The default channel view leaves out orders that aren't retail sales (draft orders, and for BioSteel Influencer Hero and marketplace orders) — pick \"All channels\" to include them, e.g. when checking against Shopify's own report.")],
        ['element' => '#asp-presets', 'intro' => __('Shortcuts for common date ranges. They keep the rest of your filters.')],
        ['element' => '#asp-kpi-asp', 'intro' => __('The headline: what a unit actually sold for after every discount and refund, for the whole range.')],
        ['element' => '#asp-kpi-depth', 'intro' => __('How far below list price, blended. List includes the pre-subscription price, so subscription discounts count here too.')],
        ['element' => '#asp-key', 'intro' => __("What each price and column means, in plain terms. List ASP → product ASP → ASP steps down through the product's own discounts, then whole-order discounts.")],
        ['element' => '#asp-mix', 'intro' => __('Splits the discount dollars by where they came from: order-level (mostly Rivo and order codes), line-level sale pricing, and subscription pricing.')],
        ['element' => '#asp-chart-card', 'intro' => __('ASP against list ASP over time, with units underneath. The dotted product ASP line is ASP before Rivo and order codes — the gap down to ASP is what those cost per unit. A dip in ASP with a spike in units is a sale working; a dip with flat units is margin given away. Hover for details, click to see the discounts behind a period.')],
        ['element' => '#asp-tabs', 'intro' => __('One row per variant (or product) for the whole range — click one to chart just that variant. The second tab has one row per period; click a row for its discounts.')],
        ['element' => '#asp-export', 'intro' => __("Download whichever table you're looking at, with the current filters.")],
        ['element' => '#asp-legend', 'intro' => __('Worth reading once: exactly how each number is worked out, and how to check it against Shopify.')],
        ['element' => '#asp-ingest', 'intro' => __('Where the data stands: the order history, order updates, and the nightly catch-up that covers anything missed.')],
    ];
    $pageConfig = [
        'periods' => $periods,
        'drilldownUrl' => route($routeBase.'.drilldown', $query),
        't' => [
            'asp' => __('ASP'), 'productAsp' => __('Product ASP'), 'listAsp' => __('List ASP'), 'units' => __('Net units'),
            'depth' => __('Discount depth'), 'top' => __('Top discounts'), 'loading' => __('Loading…'),
            'failed' => __("Couldn't load the discounts for this period."), 'none' => __('No discounts in this period — every unit sold at list.'),
            'group' => __('Discount'), 'level' => __('Level'), 'lines' => __('Lines'), 'amount' => __('Discount $'), 'codes' => __('codes'),
            'levels' => ['order' => __('Order'), 'line' => __('Line'), 'plan' => __('Subscription')],
        ],
    ];
@endphp
<x-layouts.app :title="__(':store Average Selling Price Tracker', ['store' => $storeName])" :breadcrumb="$storeName.' Shopify'">
    <x-slot:subtitle>{{ __("What each product and variant actually sold for after every discount — sale pricing, subscription pricing, Rivo redemptions and order codes — so a unit's real price can be followed over time. Shopify's own dashboard shows average order value, which isn't the same thing.") }}</x-slot:subtitle>
    <x-slot:actions>
        <x-tour-button id="asp-tour-btn" :steps="$tourSteps" />
    </x-slot:actions>

    @if ($ingest['lines'] === 0)
        <x-notice tone="warn" id="asp-empty-state" :title="__('No order history yet')">
            @if ($backfill === null)
                {{ __('No order history yet. It arrives with the import from the old app; this app\'s own backfill (:cmd) stays off until cutover.', ['cmd' => 'asp:backfill '.$filters['store']]) }}
            @elseif ($backfill->status === 'failed')
                {{ __('The backfill failed: :error', ['error' => $backfill->errors]) }}
            @else
                {{ __('The backfill is :status — this page fills in once Shopify finishes exporting the orders and the import runs. :notes', ['status' => $backfill->status, 'notes' => $backfill->notes]) }}
            @endif
        </x-notice>
    @endif

    <x-card id="asp-filters">
        <form method="get" class="filter-bar" id="asp-filter-form">
            <label class="field">
                <span>{{ __('From') }}</span>
                <input type="date" name="from" class="input" value="{{ $filters['from'] }}">
            </label>
            <label class="field">
                <span>{{ __('To') }}</span>
                <input type="date" name="to" class="input" value="{{ $filters['to'] }}">
            </label>
            <label class="field">
                <span>{{ __('Group by') }}</span>
                <select name="granularity" class="input">
                    @foreach ($granularityLabel as $key => $label)
                        <option value="{{ $key }}" @selected($filters['granularity'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <div class="field">
                <label for="asp-product">{{ __('Product') }}</label>
                <input type="search" class="input" id="asp-product-search" placeholder="{{ __('Type to filter products…') }}" autocomplete="off" aria-label="{{ __('Filter the product list') }}">
                <select name="product_id" id="asp-product" class="input">
                    <option value="">{{ __('All products') }}</option>
                    @foreach ($products as $product)
                        <option value="{{ $product['id'] }}" @selected($filters['product_id'] === $product['id'])>{{ $product['title'] }}</option>
                    @endforeach
                </select>
            </div>
            @if ($variants !== [])
                <label class="field">
                    <span>{{ __('Variant') }}</span>
                    <select name="variant_id" id="asp-variant" class="input">
                        <option value="">{{ __('All variants') }}</option>
                        @foreach ($variants as $variant)
                            <option value="{{ $variant['id'] }}" @selected($filters['variant_id'] === $variant['id'])>{{ $variant['title'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="field">
                <span>{{ __('Order type') }}</span>
                <select name="order_type" class="input">
                    <option value="all" @selected($filters['order_type'] === 'all')>{{ __('All') }}</option>
                    <option value="one_time" @selected($filters['order_type'] === 'one_time')>{{ __('One-time') }}</option>
                    <option value="subscription" @selected($filters['order_type'] === 'subscription')>{{ __('Subscription') }}</option>
                </select>
            </label>
            <label class="field">
                <span>{{ __('Channel') }}</span>
                <select name="channel" class="input">
                    <option value="{{ AspReportService::CHANNEL_RETAIL }}" @selected($filters['channel'] === AspReportService::CHANNEL_RETAIL)>{{ __($defaultViewLabel) }}</option>
                    <option value="{{ AspReportService::CHANNEL_ALL }}" @selected($filters['channel'] === AspReportService::CHANNEL_ALL)>{{ __('All channels') }}</option>
                    @foreach ($channels as $channel)
                        <option value="{{ $channel }}" @selected($filters['channel'] === $channel)>{{ $channel }}</option>
                    @endforeach
                </select>
            </label>
            <label class="check">
                <input type="checkbox" name="include_free" value="1" @checked($filters['include_free'])>
                <span>{{ __('Include free items') }}</span>
            </label>
            <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
        </form>
        <nav class="flex flex-wrap gap-1" id="asp-presets" aria-label="{{ __('Quick ranges') }}">
            @foreach ($presets as $label => [$presetFrom, $presetTo])
                @php $isOn = $filters['from'] === $presetFrom->toDateString() && $filters['to'] === $presetTo->toDateString(); @endphp
                <a class="btn btn-sm {{ $isOn ? 'btn-primary' : '' }}" @if ($isOn) aria-current="true" @endif
                   href="{{ route($routeBase.'.index', ['from' => $presetFrom->toDateString(), 'to' => $presetTo->toDateString()] + $query) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </x-card>

    <x-stat-row id="asp-kpis" class="stat-row-tight">
        <x-stat-tile id="asp-kpi-asp" :label="__('ASP')" :value="$money($summary['asp'])" :sub="__('Product ASP :value before order-level discounts', ['value' => $money($summary['product_asp'])])" />
        <x-stat-tile id="asp-kpi-list" :label="__('List ASP')" :value="$money($summary['list_asp'])" :sub="__('Before any discount')" />
        <x-stat-tile id="asp-kpi-depth" :label="__('Discount depth')" :value="$pct($summary['discount_depth'])" :sub="__('Blended % off list')" />
        <x-stat-tile id="asp-kpi-units" :label="__('Net units')" :value="$int($summary['net_qty'])" :sub="__(':gross sold, :refunded refunded', ['gross' => $int($summary['gross_qty']), 'refunded' => $int($summary['refunded_qty'])])" />
        <x-stat-tile id="asp-kpi-revenue" :label="__('Net revenue')" :value="$money($summary['net_revenue'])" :sub="__('CAD, pre-tax, pre-shipping')" />
        <x-stat-tile id="asp-kpi-full" :label="__('Full-price share')" :value="$pct($summary['full_price_share'])" :sub="__('Units sold with no discount')" />
    </x-stat-row>

    {{-- Always-visible plain-language key; the full method is in "How this is calculated". --}}
    <x-card id="asp-key" :title="__('Key')">
        <div class="grid gap-4 lg:grid-cols-3 text-sm">
            <div><div class="font-semibold">{{ __('List ASP') }}</div><p class="muted">{{ __('The regular price, before any discount.') }}</p></div>
            <div><div class="font-semibold">{{ __('Product ASP') }}</div><p class="muted">{{ __("After the product's own discounts (sale pricing, product codes, subscription price), before discounts on the whole order (Rivo rewards, \"\$X off your order\" codes). Shows how the product itself is priced.") }}</p></div>
            <div><div class="font-semibold">{{ __('ASP') }}</div><p class="muted">{{ __('What a unit actually brought in after every discount and refund. The gap from product ASP is what loyalty and cart codes cost per unit.') }}</p></div>
        </div>
        <dl class="scope-list scope-list-flush">
            <dt>{{ __('Depth') }}</dt><dd>{{ __('How far below list price units sold, all discounts combined.') }}</dd>
            <dt>{{ __('Full price') }}</dt><dd>{{ __('Share of units sold with no discount at all.') }}</dd>
            <dt>{{ __('Order-level share') }}</dt><dd>{{ __('Share of the discount $ that came from whole-order discounts (Rivo, order codes).') }}</dd>
            <dt>{{ __('Subscription share') }}</dt><dd>{{ __('Share of the discount $ that came from subscription pricing.') }}</dd>
            <dt>{{ __('Order / Line / Subscription $') }}</dt><dd>{{ __('Discount dollars by type: whole-order, product-specific (sale pricing, product codes), and subscription.') }}</dd>
            <dt>{{ __('Free units') }}</dt><dd>{{ __('Units that sold for $0 (gifts, BOGO, replacements).') }}</dd>
        </dl>
        <p class="text-sm"><a href="#asp-legend" data-open-details="asp-legend">{{ __('Exactly how each number is worked out') }}</a></p>
    </x-card>

    <p class="muted text-sm" id="asp-mix">
        {{ __('Where the discount came from:') }}
        <strong>{{ $pct($summary['order_discount_share']) }}</strong> {{ __('order-level (Rivo, order codes)') }} ·
        <strong>{{ $pct($summary['line_discount_share']) }}</strong> {{ __('line-level (sale pricing, product codes)') }} ·
        <strong>{{ $pct($summary['selling_plan_discount_share']) }}</strong> {{ __('subscription pricing') }}.
        @if ($summary['free_qty'] > 0)
            {{ trans_choice(':count free unit (gifts, BOGO, replacements) :verb.|:count free units (gifts, BOGO, replacements) :verb.', $summary['free_qty'], ['count' => number_format($summary['free_qty']), 'verb' => $filters['include_free'] ? __('included') : __('left out of ASP')]) }}
        @endif
    </p>

    <x-card id="asp-chart-card" :title="__('ASP, product ASP and list ASP, per :period', ['period' => $period])">
        <x-slot:actions><span class="muted text-sm">{{ __('Click a point or bar to see the discounts behind it.') }}</span></x-slot:actions>
        <div class="chart-box"><canvas id="asp-price-chart" role="img" aria-label="{{ __('ASP, product ASP and list ASP over time') }}"></canvas></div>
        <div class="label">{{ __('Net units') }}</div>
        <div class="chart-box chart-box-short"><canvas id="asp-units-chart" role="img" aria-label="{{ __('Net units over time') }}"></canvas></div>
    </x-card>

    <x-card>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <x-tabs id="asp-tabs" :tabs="['breakdown' => $groupBy === 'product' ? __('By product') : __('By variant'), 'periods' => __('By :period', ['period' => $period])]" :active="$tab" />
            <a class="btn btn-sm" id="asp-export" href="{{ route($routeBase.'.export-csv', ($tab === 'periods' ? ['table' => 'periods'] : ['table' => 'breakdown', 'group_by' => $groupBy]) + $query) }}"><x-icon name="csv" size="sm" />{{ __('Export CSV') }}</a>
        </div>

        @if ($tab === 'breakdown')
            <div class="segmented segmented-inline" role="group" aria-label="{{ __('Rows') }}">
                <a @if ($groupBy === 'variant') aria-current="true" @endif href="{{ route($routeBase.'.index', ['group_by' => 'variant'] + $query) }}">{{ __('Variants') }}</a>
                <a @if ($groupBy === 'product') aria-current="true" @endif href="{{ route($routeBase.'.index', ['group_by' => 'product'] + $query) }}">{{ __('Products') }}</a>
            </div>
            @if ($breakdown === [])
                <x-empty-state :title="__('No sales match these filters.')" icon="tag" />
            @else
                <x-data-table :caption="$groupBy === 'product' ? 'By product' : 'By variant'">
                    <thead>
                        <tr>
                            <x-th sort>{{ $groupBy === 'product' ? __('Product') : __('Product / variant') }}</x-th>
                            <x-th sort type="number">{{ __('Net units') }}</x-th>
                            <x-th sort type="number">{{ __('Net revenue') }}</x-th>
                            <x-th sort type="number">{{ __('ASP') }}</x-th>
                            <x-th sort type="number">{{ __('Product ASP') }}</x-th>
                            <x-th sort type="number">{{ __('List ASP') }}</x-th>
                            <x-th sort type="number">{{ __('Depth') }}</x-th>
                            <x-th sort type="number">{{ __('Full price') }}</x-th>
                            <x-th sort type="number">{{ __('Order-level share') }}</x-th>
                            <x-th sort type="number">{{ __('Subscription share') }}</x-th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($breakdown as $row)
                            <tr>
                                <td class="wrap-cell" data-value="{{ $row['product_title'] }}">
                                    <a href="{{ route($routeBase.'.index', array_merge($query, ['product_id' => $row['product_id'], 'variant_id' => $groupBy === 'variant' ? $row['id'] : null])) }}">{{ $row['product_title'] }}</a>
                                    @if ($groupBy === 'variant')
                                        <div class="muted text-xs">{{ $row['variant_title'] ?: __('Default') }}@if ($row['sku']) · {{ $row['sku'] }}@endif</div>
                                    @endif
                                </td>
                                <td class="num" data-value="{{ $row['net_qty'] }}">{{ $int($row['net_qty']) }}</td>
                                <td class="num" data-value="{{ $row['net_revenue'] }}">{{ $money($row['net_revenue']) }}</td>
                                <td class="num font-semibold" data-value="{{ $row['asp'] }}">{{ $money($row['asp']) }}</td>
                                <td class="num" data-value="{{ $row['product_asp'] }}">{{ $money($row['product_asp']) }}</td>
                                <td class="num" data-value="{{ $row['list_asp'] }}">{{ $money($row['list_asp']) }}</td>
                                <td class="num" data-value="{{ $row['discount_depth'] }}">{{ $pct($row['discount_depth']) }}</td>
                                <td class="num" data-value="{{ $row['full_price_share'] }}">{{ $pct($row['full_price_share']) }}</td>
                                <td class="num" data-value="{{ $row['order_discount_share'] }}">{{ $pct($row['order_discount_share']) }}</td>
                                <td class="num" data-value="{{ $row['selling_plan_discount_share'] }}">{{ $pct($row['selling_plan_discount_share']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-data-table>
            @endif
        @else
            <x-data-table caption="By period" id="asp-period-table">
                <thead>
                    <tr>
                        <x-th>{{ __('Period') }}</x-th>
                        <x-th sort type="number">{{ __('Net units') }}</x-th>
                        <x-th sort type="number">{{ __('Net revenue') }}</x-th>
                        <x-th sort type="number">{{ __('ASP') }}</x-th>
                        <x-th sort type="number">{{ __('Product ASP') }}</x-th>
                        <x-th sort type="number">{{ __('List ASP') }}</x-th>
                        <x-th sort type="number">{{ __('Depth') }}</x-th>
                        <x-th sort type="number">{{ __('Full price') }}</x-th>
                        <x-th sort type="number">{{ __('Order-level $') }}</x-th>
                        <x-th sort type="number">{{ __('Line-level $') }}</x-th>
                        <x-th sort type="number">{{ __('Subscription $') }}</x-th>
                        <x-th sort type="number">{{ __('Free units') }}</x-th>
                        <x-th>{{ __('Top discounts') }}</x-th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_reverse($periods) as $p)
                        <tr class="row-link" tabindex="0" data-period="{{ $p['period'] }}" title="{{ __('Show the discounts behind this period') }}">
                            <td class="whitespace-nowrap">{{ $p['label'] }}</td>
                            <td class="num" data-value="{{ $p['net_qty'] }}">{{ $int($p['net_qty']) }}</td>
                            <td class="num" data-value="{{ $p['net_revenue'] }}">{{ $money($p['net_revenue']) }}</td>
                            <td class="num font-semibold" data-value="{{ $p['asp'] }}">{{ $money($p['asp']) }}</td>
                            <td class="num" data-value="{{ $p['product_asp'] }}">{{ $money($p['product_asp']) }}</td>
                            <td class="num" data-value="{{ $p['list_asp'] }}">{{ $money($p['list_asp']) }}</td>
                            <td class="num" data-value="{{ $p['discount_depth'] }}">{{ $pct($p['discount_depth']) }}</td>
                            <td class="num" data-value="{{ $p['full_price_share'] }}">{{ $pct($p['full_price_share']) }}</td>
                            <td class="num" data-value="{{ $p['order_discount'] }}">{{ $money($p['order_discount']) }}</td>
                            <td class="num" data-value="{{ $p['line_discount'] }}">{{ $money($p['line_discount']) }}</td>
                            <td class="num" data-value="{{ $p['selling_plan_discount'] }}">{{ $money($p['selling_plan_discount']) }}</td>
                            <td class="num" data-value="{{ $p['free_qty'] }}">{{ $int($p['free_qty']) }}</td>
                            <td class="wrap-cell muted text-xs">{{ implode(', ', array_column($p['top_labels'], 'label')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-data-table>
        @endif
    </x-card>

    <details class="card scope-card" id="asp-legend">
        <summary class="card-body font-semibold">{{ __('How this is calculated') }}</summary>
        <dl class="scope-list">
            <dt>{{ __('ASP') }}</dt>
            <dd>{{ __("Net revenue ÷ net units. Net revenue is each line's price × quantity minus every discount Shopify put on that line, minus refunds — CAD, before tax and shipping. Orders paid in USD count in CAD as Shopify converted them on the order. Order-level discounts (Rivo, \"\$X off\" codes) are spread across the order's lines by Shopify itself, to the penny; they're used exactly as Shopify allocated them. Gift card sales are left out; paying with a gift card isn't a discount.") }}</dd>
            <dt>{{ __('Product ASP') }}</dt>
            <dd>{{ __("ASP with the order-level discounts added back: what the product's own pricing (sale pricing, product codes, subscription price) netted, before Rivo redemptions and \"\$X off your order\" codes. Only the order-level discount on units that were kept is added back — a refunded line's share went back with the refund. The gap between product ASP and ASP is what loyalty and cart codes cost per unit.") }}</dd>
            <dt>{{ __('List ASP') }}</dt>
            <dd>{{ __("The average price before any discount, so a list-price change shows separately from discounting. For subscription lines Shopify only records the already-discounted subscription price, so list is what the same variant sold for on one-time orders around that date (nearest earlier day within 90 days, else the nearest later day, else the variant's current price). The gap is the subscription discount.") }}</dd>
            <dt>{{ __('Discount depth') }}</dt>
            <dd>{{ __('1 − ASP ÷ list ASP: how far below list units sold, blended across every kind of discount.') }}</dd>
            <dt>{{ __('Order- vs line-level') }}</dt>
            <dd>{{ __("Order-level: discounts on the whole order — Rivo loyalty redemptions (CAP- codes on Canadian Protein, BIO- codes on BioSteel, grouped as \"Rivo loyalty\"), \"\$X off your order\" codes, manual order discounts. Line-level: automatic sale pricing (\"Buy more save more\") and product-specific codes. Subscription: the selling-plan price. Shipping discounts aren't counted.") }}</dd>
            <dt>{{ __('Refunds & cancellations') }}</dt>
            <dd>{{ __("Refunds come off the original order's date, so a month shows what the units sold that month actually netted. Removing a line in an order edit counts as a refund. Refunds not tied to a line (shipping refunds, goodwill credits) don't change ASP. Cancelled and test orders are left out entirely.") }}</dd>
            <dt>{{ __('Free items') }}</dt>
            <dd>{{ __("Lines that netted \$0 (gifts, BOGO, replacement orders) would drag ASP toward zero, so they're left out unless \"Include free items\" is ticked, and counted separately.") }}</dd>
            <dt>{{ __('Weeks & days') }}</dt>
            <dd>{{ __("Days are in the shop's own time zone (:tz); weeks start Monday.", ['tz' => $storeConfig['timezone']]) }}</dd>
            <dt>{{ __('Checking against Shopify') }}</dt>
            <dd>{{ __("Shopify Analytics → Sales by product should match net units and net revenue for the same range with \"All channels\" selected. Shopify puts a refund on the day it was issued rather than on the original order, so months with late refunds can differ slightly; the totals over a long range shouldn't.") }}</dd>
        </dl>
    </details>

    <x-card id="asp-ingest" :title="__('Data status')">
        <div class="grid gap-4 md:grid-cols-3 text-sm">
            <div>
                <div class="font-semibold">{{ __('History') }}</div>
                @if ($backfill)
                    {{ __('Backfill since :since — :status', ['since' => $backfill->since?->toDateString(), 'status' => $backfill->status]) }}
                    @if ($backfill->finished_at) ({{ $backfill->finished_at->diffForHumans() }}) @endif
                    <div class="muted">{{ trans_choice(':count line stored|:count lines stored', $ingest['lines'], ['count' => number_format($ingest['lines'])]) }}</div>
                @else
                    <span class="muted">{{ __('Not started yet.') }}</span>
                @endif
            </div>
            <div>
                <div class="font-semibold">{{ __('Order updates') }}</div>
                {{ __('Last queued-order run:') }} {{ $ingest['last_pending']?->started_at?->diffForHumans() ?? __('none yet') }}
                <div class="muted">{{ __('Nightly catch-up:') }} {{ $ingest['last_catch_up']?->started_at?->diffForHumans() ?? __('none yet') }}</div>
            </div>
            <div>
                <div class="font-semibold">{{ __('Queue') }}</div>
                {{ trans_choice(':count order waiting|:count orders waiting', $ingest['queued'], ['count' => $ingest['queued']]) }}
                @if ($ingest['parked'] > 0)
                    <div><x-status-badge tone="bad" icon="alert">{{ trans_choice(':count order failed 5 times and is parked|:count orders failed 5 times and are parked', $ingest['parked'], ['count' => $ingest['parked']]) }}</x-status-badge></div>
                @endif
                @if ($ingest['unresolved_list_prices'] > 0)
                    <div class="muted">{{ trans_choice(':count subscription line still waiting for a list price|:count subscription lines still waiting for a list price', $ingest['unresolved_list_prices'], ['count' => $ingest['unresolved_list_prices']]) }}</div>
                @endif
            </div>
        </div>
    </x-card>

    <x-modal id="asp-drilldown" :title="__('Discounts')" class="modal-wide">
        <div id="asp-drilldown-body"></div>
    </x-modal>

    <script type="application/json" id="asp-config">@json($pageConfig)</script>
    @vite('resources/js/tools/asp-tracker.js')
</x-layouts.app>
