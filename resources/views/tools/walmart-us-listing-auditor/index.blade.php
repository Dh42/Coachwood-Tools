{{-- Walmart US Listing Auditor, ported from the old app onto the new design. Read-only. --}}
<x-layouts.app title="Walmart US Listing Auditor" breadcrumb="Walmart">
    <x-slot:subtitle>{{ __("Walmart's own Listing Quality scores and issues for every audited US Marketplace SKU. Read-only. Our own internal rule scoring isn't implemented yet.") }}</x-slot:subtitle>

    <section class="section">
        <div class="section-head">
            <h2 class="section-title">{{ __('Catalog rollup') }}</h2>
            @if ($catalogScore)
                <x-freshness-badge :updated-at="$catalogScore['run_at']" :stale-after-hours="30" />
            @endif
        </div>
        @if ($catalogScore === null)
            <x-card>
                <x-empty-state :title="__('No catalog score yet')" icon="cart">{{ __("The nightly audit hasn't completed a run yet.") }}</x-empty-state>
            </x-card>
        @else
            <x-stat-row>
                @foreach ([
                    'Listing Quality' => $catalogScore['listing_quality'],
                    'Content' => $catalogScore['content_score'],
                    'Offer' => $catalogScore['offer_score'],
                    'Rating & Reviews' => $catalogScore['rating_review_score'],
                    'Price' => $catalogScore['price_score'],
                    'Shipping' => $catalogScore['shipping_score'],
                    'Transactibility' => $catalogScore['transactibility_score'],
                ] as $label => $value)
                    <x-stat-tile :label="__($label)" :value="$value !== null ? number_format($value, 1) : null" />
                @endforeach
                <x-stat-tile :label="__('Items')" :value="$catalogScore['item_count'] !== null ? number_format($catalogScore['item_count']) : null" />
            </x-stat-row>
        @endif
    </section>

    <div class="grid gap-4 lg:grid-cols-5 mb-4">
        <x-card :title="__('Listing Quality trend')" class="lg:col-span-3">
            @if ($scoreHistory->isEmpty())
                <p class="muted text-sm">{{ __('No history yet — check back after a few nightly runs.') }}</p>
            @else
                <div class="chart-box"><canvas id="chart-trend" role="img" aria-label="{{ __('Catalog Listing Quality score over the last 30 days, 0 to 100') }}"></canvas></div>
            @endif
        </x-card>

        <x-card :title="__('Top issues by occurrence')" class="lg:col-span-2">
            <div class="table-wrap scroll-box">
                <table class="table">
                    <thead><tr><th scope="col">{{ __('Code') }}</th><th scope="col" class="num">{{ __('Occurrences') }}</th></tr></thead>
                    <tbody>
                        @forelse ($topIssues as $issue)
                            <tr><td><code>{{ $issue['code'] }}</code></td><td class="num">{{ number_format($issue['occurrences']) }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="muted">{{ __('No issues recorded — nothing has been audited yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    <x-card :title="__('Every audited SKU')">
        @if ($audits->isEmpty())
            <x-empty-state :title="__('No SKUs audited yet')" icon="cart">{{ __("The nightly ingest hasn't completed a run yet.") }}</x-empty-state>
        @else
            <x-data-table caption="Audited SKUs" csv="walmart-us-listing-audit.csv">
                <thead>
                    <tr>
                        <x-th sort>{{ __('SKU') }}</x-th>
                        <x-th sort>{{ __('Product') }}</x-th>
                        <x-th sort type="number">{{ __('Listing Quality') }}</x-th>
                        <x-th sort type="number">{{ __('Issues') }}</x-th>
                        <x-th sort>{{ __('As of') }}</x-th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($audits as $row)
                        <tr>
                            <td><a href="{{ route('tools.walmart-us-listing-auditor.show', $row['sku']) }}">{{ $row['sku'] }}</a></td>
                            <td class="truncate-cell">{{ $row['product_name'] ?? '—' }}</td>
                            <td class="num" data-value="{{ $row['listing_quality'] ?? '' }}">@include('tools.walmart-us-listing-auditor._score', ['score' => $row['listing_quality']])</td>
                            <td class="num">{{ $row['issue_count'] }}</td>
                            <td class="muted">{{ $row['as_of_date'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-data-table>
        @endif
    </x-card>

    @if ($scoreHistory->isNotEmpty())
        <script type="application/json" id="walmart-trend-data">@json($scoreHistory->map(fn ($row) => ['x' => $row['run_at']->toDateString(), 'y' => $row['listing_quality']])->values())</script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const history = JSON.parse(document.getElementById('walmart-trend-data').textContent);
                window.themedChart(document.getElementById('chart-trend'), {
                    type: 'line',
                    data: { labels: history.map((p) => p.x), datasets: [{ data: history.map((p) => p.y), borderWidth: 2, pointRadius: 3, tension: 0 }] },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, maxTicksLimit: 8 } }, y: { min: 0, max: 100 } },
                    },
                });
            });
        </script>
    @endif
</x-layouts.app>
