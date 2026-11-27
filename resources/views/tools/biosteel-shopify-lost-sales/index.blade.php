{{-- BioSteel Shopify Lost Sales (renamed from Stockout, decision D7), ported onto the new design. Read-only. --}}
@php
    $tourSteps = [
        ['element' => '#stockout-empty-state', 'intro' => __('No estimates yet — this tool has nothing to show until stockout:estimate-lost-sales has run at least once.')],
        ['element' => '#stockout-range-form', 'intro' => __("Filter to a preset window (7/14/30/60 days, or \"All time\") or pick a custom range and click Apply. The currently active preset stays highlighted. A stockout that overlaps the window counts, but only for its portion inside it — a long-running stockout isn't double-counted or clipped.")],
        ['element' => '#stockout-kpis', 'intro' => __('Totals for whatever range is currently selected above, plus a normal/thin/insufficient breakdown of how much to trust them.')],
        ['element' => '#stockout-legend', 'intro' => __("Worth reading once: baseline demand comes from the 3 most recent in-stock days (widened to 14 if too thin), every figure is gross with one deliberate RTD→PET exception, and confidence is never buried — it's a column, not a footnote.")],
        ['element' => '#stockout-table', 'intro' => __('Ranked by revenue lost, highest first — the ranking is the reliable part of this tool, the dollar total underneath is a rough estimate. A row marked "PET cover" has been shortened because a PET-bottle successor covered part of the stockout; the note under the product gives the exact numbers.')],
    ];
    $confidence = [
        'normal' => ['tone' => 'good', 'icon' => 'check', 'label' => __('Normal')],
        'thin_fallback' => ['tone' => 'warn', 'icon' => 'alert', 'label' => __('Thin fallback')],
        'insufficient_data' => ['tone' => 'mute', 'icon' => 'info', 'label' => __('Insufficient data')],
    ];
    $today = now()->toDateString();
    $presets = collect([7, 14, 30, 60])->map(fn (int $days) => [
        'label' => __(':n days', ['n' => $days]),
        'query' => ['start' => now()->subDays($days)->toDateString(), 'end' => $today],
    ])->push(['label' => __('All time'), 'query' => []]);
@endphp
<x-layouts.app title="BioSteel Shopify Lost Sales" breadcrumb="BioSteel Shopify">
    <x-slot:subtitle>{{ __('BioSteel core products only (merch excluded). Every figure below is a gross estimate, not an exact count — the ranking is the reliable part, the dollar total is a rough approximation. See "How this is calculated" below before quoting a number.') }}</x-slot:subtitle>
    <x-slot:actions>
        <x-tour-button id="stockout-tour-btn" :steps="$tourSteps" />
    </x-slot:actions>

    @if (! $latestComputedAt)
        <x-card id="stockout-empty-state">
            <x-empty-state :title="__('No estimates yet')" icon="trend-down">
                {{ __('No estimates yet. This is populated by') }} <code>stockout:estimate-lost-sales</code>{{ __(', run manually after the historical import commands — see README.') }}
            </x-empty-state>
        </x-card>
    @else
        <x-card id="stockout-range-form">
            <x-filter-bar :action="route('tools.biosteel-shopify-lost-sales.index')">
                <label class="field">
                    <span>{{ __('From') }}</span>
                    <input type="date" name="start" value="{{ $rangeStart }}" class="input">
                </label>
                <label class="field">
                    <span>{{ __('To') }}</span>
                    <input type="date" name="end" value="{{ $rangeEnd }}" class="input">
                </label>
                <x-slot:actions>
                    <nav class="flex flex-wrap gap-1 ml-auto" aria-label="{{ __('Quick ranges') }}">
                        @foreach ($presets as $preset)
                            @php $isOn = ($preset['query']['start'] ?? null) === $rangeStart && ($preset['query']['end'] ?? null) === $rangeEnd; @endphp
                            <a class="btn btn-sm {{ $isOn ? 'btn-primary' : '' }}" @if ($isOn) aria-current="true" @endif
                               href="{{ route('tools.biosteel-shopify-lost-sales.index', $preset['query']) }}">{{ $preset['label'] }}</a>
                        @endforeach
                    </nav>
                </x-slot:actions>
            </x-filter-bar>
            <p class="muted text-sm">{{ __("A stockout that overlaps the selected range counts, but only for its portion inside it — an ongoing or long-running stockout isn't double-counted or clipped from the totals.") }}</p>
        </x-card>

        <x-stat-row id="stockout-kpis">
            <x-stat-tile :label="__('Gross revenue lost (est.)')" :value="'$'.number_format($summary['gross_revenue_lost'], 0)" />
            <x-stat-tile :label="__('Gross units lost (est.)')" :value="number_format($summary['gross_units_lost'], 0)" />
            <x-stat-tile :label="__('Stockout intervals measured')" :value="number_format($summary['count'])" />
            <div class="stat-tile">
                <div class="label">{{ __('Normal / thin / insufficient') }}</div>
                <div class="flex flex-wrap gap-1 mt-1">
                    @foreach ($confidence as $key => $c)
                        <x-status-badge :tone="$c['tone']" :icon="$c['icon']">{{ $confidenceCounts[$key] }} {{ $c['label'] }}</x-status-badge>
                    @endforeach
                </div>
            </div>
        </x-stat-row>

        <details class="card scope-card" id="stockout-legend">
            <summary class="card-body font-semibold">{{ __('How this is calculated') }}</summary>
            <dl class="scope-list">
                <dt>{{ __('Stockout intervals') }}</dt>
                <dd>{{ __("Days a core product was tracked, set to stop selling at zero, active, and published to the Online Store — reconstructed from Shopify's own historical inventory data (day + product level, not exact per-SKU before this tool started capturing live data).") }}</dd>
                <dt>{{ __('Baseline demand') }}</dt>
                <dd>{{ __('The average of the 3 most recent days the product was actually in stock right before the stockout began. If those 3 days sold fewer than 3 units combined, widened to the most recent 14 in-stock days instead, flagged "thin" below.') }}</dd>
                <dt>{{ __('Gross, not net') }}</dt>
                <dd>{{ __('No adjustment for demand that shifted to a sibling flavor/size instead of being lost outright — every number here is the full gross estimate.') }}</dd>
                <dt>{{ __('RTD → PET exception') }}</dt>
                <dd>{{ __("One deliberate exception to \"gross, not net\": when an old RTD tetra-pack SKU is out of stock but its PET-bottle successor is in stock, that's not a real lost sale. A row marked \"PET cover\" has been shortened to only the day(s) both were unavailable; the note under the product gives the exact numbers.") }}</dd>
                <dt>{{ __('Confidence') }}</dt>
                <dd>
                    <ul class="flex flex-col gap-2">
                        <li><x-status-badge tone="good" icon="check">{{ __('Normal') }}</x-status-badge> {{ __('full 3-day sample with enough sales to trust.') }}</li>
                        <li><x-status-badge tone="warn" icon="alert">{{ __('Thin fallback') }}</x-status-badge> {{ __('widened to 14 days because the recent sample was too small.') }}</li>
                        <li><x-status-badge tone="mute" icon="info">{{ __('Insufficient data') }}</x-status-badge> {{ __('even the widened window came up short — treat this number as a rough floor, not a real estimate.') }}</li>
                    </ul>
                </dd>
                <dt>{{ __('Historical coverage') }}</dt>
                <dd>{{ __('Inventory :invFrom to :invTo, sales :salesFrom to :salesTo.', [
                    'invFrom' => $historicalCoverage['inventory_from'] ?? '—',
                    'invTo' => $historicalCoverage['inventory_to'] ?? '—',
                    'salesFrom' => $historicalCoverage['sales_from'] ?? '—',
                    'salesTo' => $historicalCoverage['sales_to'] ?? '—',
                ]) }}</dd>
            </dl>
        </details>

        <x-card id="stockout-table" :title="__('Stockouts by revenue lost')">
            @if ($estimates->isEmpty())
                <x-empty-state :title="__('No stockouts in this range')" icon="trend-down">{{ __('Try a wider range, or All time.') }}</x-empty-state>
            @else
                <x-data-table caption="Stockouts by revenue lost" csv="biosteel-shopify-lost-sales.csv">
                    <thead>
                        <tr>
                            <x-th sort>{{ __('Product') }}</x-th>
                            <x-th sort>{{ __('SKU') }}</x-th>
                            <x-th sort>{{ __('Started') }}</x-th>
                            <x-th sort>{{ __('Ended') }}</x-th>
                            <x-th sort type="number">{{ __('Days OOS') }}</x-th>
                            <x-th sort type="number">{{ __('Baseline $/day') }}</x-th>
                            <x-th sort type="number">{{ __('Gross units lost') }}</x-th>
                            <x-th sort type="number">{{ __('Gross revenue lost') }}</x-th>
                            <x-th sort>{{ __('Confidence') }}</x-th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($estimates as $e)
                            @php $c = $confidence[$e->baseline_confidence] ?? ['tone' => 'mute', 'icon' => null, 'label' => $e->baseline_confidence]; @endphp
                            <tr>
                                <td class="wrap-cell">
                                    {{ $e->product_title }}
                                    @if ($e->substitution_note)
                                        <x-status-badge tone="info" icon="info">{{ __('PET cover') }}</x-status-badge>
                                        <div class="muted text-xs">{{ $e->substitution_note }}</div>
                                    @endif
                                </td>
                                <td><code>{{ $e->sku ?? '—' }}</code></td>
                                <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($e->started_at)->toDateString() }}</td>
                                <td class="whitespace-nowrap">{{ $e->ended_at ? \Illuminate\Support\Carbon::parse($e->ended_at)->toDateString() : __('Ongoing') }}</td>
                                <td class="num" data-value="{{ $e->window_days_out_of_stock }}">{{ number_format($e->window_days_out_of_stock, 1) }}</td>
                                <td class="num" data-value="{{ $e->baseline_revenue_per_day }}">${{ number_format($e->baseline_revenue_per_day, 2) }}</td>
                                <td class="num" data-value="{{ $e->window_units_lost }}">{{ number_format($e->window_units_lost, 1) }}</td>
                                <td class="num font-semibold" data-value="{{ $e->window_revenue_lost }}">${{ number_format($e->window_revenue_lost, 2) }}</td>
                                <td><x-status-badge :tone="$c['tone']" :icon="$c['icon']">{{ $c['label'] }}</x-status-badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-data-table>
                <x-pagination :paginator="$estimates" />
            @endif
        </x-card>
    @endif
</x-layouts.app>
