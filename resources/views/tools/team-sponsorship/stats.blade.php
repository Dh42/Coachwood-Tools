{{--
    BioSteel Sponsorship Stats, ported from the old app onto the new design.
    The three charts are drawn by resources/js/tools/team-sponsorship-stats.js
    from #tss-config.
--}}
@php
    $dash = fn ($v) => $v === null ? '—' : number_format($v);
    $productLabels = $productsByMonth['products']->keys();
    $tourSteps = [
        ['element' => '#tss-totals', 'intro' => __("Sponsorship orders sent from this tool. A team sponsored twice counts twice. Unique requesters counts coaches' emails.")],
        ['element' => '#tss-months-card', 'intro' => __('Orders sent each month.')],
        ['element' => '#tss-requesters-card', 'intro' => __('The ten emails with the most sponsorship orders.')],
        ['element' => '#tss-reach', 'intro' => __("From the BioSteel Teams app, refreshed nightly: the share of sponsorships whose team has never added an event, game or practice (teams without refreshed data aren't counted), and the email addresses reachable through the sponsored teams.")],
        ['element' => '#tss-products-card', 'intro' => __('Units of each product sent per month, with a monthly average over the last three months.')],
        ['element' => '#tss-recent-card', 'intro' => __("Every sponsorship, newest first, with the team's activity from the nightly refresh. The export has every row, not only this page.")],
    ];
    $pageConfig = [
        'months' => ['labels' => $ordersByMonth->pluck('label'), 'values' => $ordersByMonth->pluck('count')],
        'requesters' => ['labels' => $topRequesters->pluck('email'), 'values' => $topRequesters->pluck('total')->map(fn ($v) => (int) $v)],
        'products' => ['months' => $productsByMonth['months'], 'series' => $productsByMonth['products']],
        't' => ['sponsorships' => __('Sponsorships')],
    ];
@endphp
<x-layouts.app :title="__('BioSteel Sponsorship Stats')" :breadcrumb="__('BioSteel Teams')">
    <x-slot:subtitle>{{ __('Order volume, top requesters, and recent history for the BioSteel Team Sponsorship tool.') }}</x-slot:subtitle>
    <x-slot:actions>
        <x-tour-button id="tss-tour-btn" :steps="$tourSteps" />
        @can('tool.team-sponsorship')
            <a href="{{ route('tools.team-sponsorship.index') }}" class="btn btn-sm">{{ __('Back to Tool') }}</a>
        @endcan
    </x-slot:actions>

    <x-stat-row id="tss-totals">
        <x-stat-tile :label="__('Total Sponsorships')" :value="number_format($totalOrders)" />
        <x-stat-tile :label="__('This Month')" :value="number_format($ordersThisMonth)" />
        <x-stat-tile :label="__('Last 30 Days')" :value="number_format($ordersLast30Days)" />
        <x-stat-tile :label="__('Unique Requesters')" :value="number_format($uniqueRequesters)" />
    </x-stat-row>

    <div class="grid gap-4 lg:grid-cols-12">
        <x-card id="tss-months-card" class="lg:col-span-7" :title="__('Sponsorships per Month')">
            @if ($ordersByMonth->isEmpty())
                <x-empty-state :title="__('No sponsorship data yet.')" icon="pie" />
            @else
                <div class="chart-box"><canvas id="tss-months-chart" role="img" aria-label="{{ __('Sponsorships per month') }}"></canvas></div>
            @endif
        </x-card>
        <x-card id="tss-requesters-card" class="lg:col-span-5" :title="__('Top 10 Requesters')">
            @if ($topRequesters->isEmpty())
                <x-empty-state :title="__('No sponsorship data yet.')" icon="users" />
            @else
                <div class="chart-box"><canvas id="tss-requesters-chart" role="img" aria-label="{{ __('Top 10 requesters by sponsorships') }}"></canvas></div>
            @endif
        </x-card>
    </div>

    <x-stat-row id="tss-reach">
        <x-stat-tile :label="__('Sponsorships w/ no events')" :value="$noEventsPct !== null ? number_format($noEventsPct, 1).'%' : '—'"
                     title="{{ __('Share of sponsorships whose team has never added a single event, game or practice in the BioSteel Teams app, among teams with refreshed data.') }}"
                     :sub="$ordersWithKnownEvents > 0 ? __(':count of :total sponsorships with data', ['count' => $ordersWithNoEvents, 'total' => $ordersWithKnownEvents]) : __('No refreshed activity data yet')" />
        <x-stat-tile :label="__('Emails captured')" :value="number_format($emailsTotal)"
                     title="{{ __('Registered players + staff + player contacts + 1 (the coach), summed per distinct sponsored team.') }}"
                     :sub="__('across :count teams with data', ['count' => $teamsWithEmailsData])" />
        <div class="stat-tile">
            <div class="label">{{ __('Activity data') }}</div>
            <x-freshness-badge :updated-at="$activityRefreshedAt" :stale-after-hours="26" />
            <div class="stat-sub">{{ __('From the BioSteel Teams app, refreshed nightly at 01:00 UTC') }}</div>
        </div>
    </x-stat-row>

    <x-card id="tss-products-card" :title="__('Products Sent per Month')">
        @if ($productsByMonth['months']->isEmpty())
            <x-empty-state :title="__('No product data yet')" icon="box">{{ __('No product data yet — new sponsorships will start populating this once shipped, and historical orders need the backfill command run.') }}</x-empty-state>
        @else
            <div class="stat-row stat-row-plain stat-row-tight">
                @foreach ($productsByMonth['monthly_averages'] as $label => $avg)
                    <x-stat-tile :label="__(':label / mo avg', ['label' => $label])" :value="number_format($avg['average'], 1)"
                                 :sub="$avg['months_counted'] < 3 ? __('based on :count mo of data', ['count' => $avg['months_counted']]) : __('last 3 months')" />
                @endforeach
            </div>
            <div class="chart-box chart-box-lg"><canvas id="tss-products-chart" role="img" aria-label="{{ __('Products sent per month, stacked by product') }}"></canvas></div>
            <div class="table-wrap">
                <table class="table">
                    <caption class="sr-only">{{ __('Products sent per month') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Month') }}</th>
                            @foreach ($productLabels as $label)
                                <th scope="col" class="num">{{ $label }}</th>
                            @endforeach
                            <th scope="col" class="num">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productsByMonth['months'] as $i => $month)
                            <tr>
                                <td>{{ $month }}</td>
                                @foreach ($productsByMonth['products'] as $values)
                                    <td class="num">{{ number_format($values[$i]) }}</td>
                                @endforeach
                                <td class="num font-semibold">{{ number_format($productsByMonth['products']->map(fn ($values) => $values[$i])->sum()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <x-card id="tss-recent-card" :title="__('Recent Sponsorships')">
        <x-slot:actions>
            @if ($totalOrders > 0)
                <a href="{{ route('tools.team-sponsorship.stats.export') }}" class="btn btn-sm"><x-icon name="csv" size="sm" />{{ __('Export all (CSV)') }}</a>
            @endif
        </x-slot:actions>
        <div class="table-wrap">
            <table class="table">
                <caption class="sr-only">{{ __('Recent sponsorships, newest first') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('Date') }}</th>
                        <th scope="col">{{ __('Team') }}</th>
                        <th scope="col">{{ __('Email') }}</th>
                        <th scope="col">{{ __('Order ID') }}</th>
                        <th scope="col" class="num" title="{{ __('Registered players + staff + player contacts + 1 (the coach): the email reach for this team, from the BioSteel Teams API') }}">{{ __('Emails') }}</th>
                        <th scope="col" class="num" title="{{ __('All-time totals from the BioSteel Teams API, refreshed once daily') }}">{{ __('Events') }}</th>
                        <th scope="col" class="num">{{ __('Games') }}</th>
                        <th scope="col" class="num">{{ __('Practices') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentOrders as $order)
                        @php $activity = $order->activity; @endphp
                        <tr>
                            <td class="whitespace-nowrap">{{ $order->sent_at->format('Y-m-d H:i') }}</td>
                            <td>{{ $order->team_name }}</td>
                            <td class="muted">{{ $order->email }}</td>
                            <td><a href="https://{{ config('services.shopify.store') }}/admin/draft_orders/{{ $order->shopify_order_id }}" target="_blank" rel="noopener" class="mono">{{ $order->shopify_order_id }}</a></td>
                            <td class="num">{{ $dash($activity?->emails_count) }}</td>
                            <td class="num">{{ $dash($activity?->events_count) }}</td>
                            <td class="num">{{ $dash($activity?->games_count) }}</td>
                            <td class="num">{{ $dash($activity?->practices_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">{{ __('No sponsorships recorded yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-pagination :paginator="$recentOrders" />
    </x-card>

    <script type="application/json" id="tss-config">@json($pageConfig)</script>
    @vite('resources/js/tools/team-sponsorship-stats.js')
</x-layouts.app>
