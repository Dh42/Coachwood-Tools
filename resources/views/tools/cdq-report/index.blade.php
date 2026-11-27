{{-- CDQ Listing Quality Report, ported from the old app onto the new design. Read-only. --}}
@php
    $gradeLabels = ['A' => 'Grade A', 'B' => 'Grade B', 'C' => 'Grade C', 'D' => 'Grade D', 'U' => 'Grade U (unscored)'];
    $unchecked = $scores->reject(fn ($row) => $row['consist_checked'])->count();
@endphp
<x-layouts.app title="CDQ Listing Quality Report" breadcrumb="Amazon · Listings">
    <x-slot:subtitle>{{ __("Our own CDQ-modeled content quality score (0–100) for every active Vendor Central ASIN — deduction-based, not Amazon's real (unpublished) number. Read-only; see the underlying ASIN's listing editor to fix anything shown here.") }}</x-slot:subtitle>

    <x-stat-row id="grade-tiles" class="stat-row-tight">
        @foreach ($gradeLabels as $grade => $label)
            <x-stat-tile :label="__($label)" :value="number_format($gradeDistribution[$grade])" />
        @endforeach
    </x-stat-row>

    @if ($scores->isNotEmpty() && $unchecked > 0)
        <x-notice tone="warn" :title="__('Consistency not checked')">
            {{ __(':n of :total scores include the consistency points (10 of 100) without the check having run. Those show as "Not checked" below. The check needs the Anthropic API and runs on Sundays after scoring.', ['n' => number_format($unchecked), 'total' => number_format($scores->count())]) }}
        </x-notice>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <x-card :title="__('Score distribution')">
            @if ($scores->isEmpty())
                <p class="muted text-sm">{{ __('Nothing scored yet.') }}</p>
            @else
                <div class="chart-box"><canvas id="chart-histogram" role="img" aria-label="{{ __('Number of ASINs in each 10-point score band, 0 to 100') }}"></canvas></div>
            @endif
        </x-card>

        <x-card :title="__('Week-over-week movement')">
            @if (! $movement['has_prior_data'])
                <p class="muted text-sm">{{ __("No snapshot from 7+ days ago yet — this catalog hasn't been tracked long enough for a comparison. Check back after the next weekly snapshot cycle.") }}</p>
            @else
                <x-stat-row class="stat-row-plain">
                    <x-stat-tile :label="__('Improved')" :value="number_format($movement['improved'])" />
                    <x-stat-tile :label="__('Declined')" :value="number_format($movement['declined'])" />
                    <x-stat-tile :label="__('Unchanged')" :value="number_format($movement['unchanged'])" />
                </x-stat-row>
                <p class="muted text-sm">{{ __('Average change: :delta pts', ['delta' => $movement['avg_change'] ?? '—']) }}</p>
            @endif
        </x-card>
    </div>

    <x-card :title="__('Top defects by points lost (across the catalog)')">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Check') }}</th>
                        <th scope="col">{{ __('Dimension') }}</th>
                        <th scope="col" class="num">{{ __('Points lost') }}</th>
                        <th scope="col" class="num">{{ __('Occurrences') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topDefects as $defect)
                        <tr>
                            <td><code>{{ $defect['check_code'] }}</code></td>
                            <td class="muted">{{ $defect['dimension'] }}</td>
                            <td class="num">{{ number_format($defect['points_lost'], 1) }}</td>
                            <td class="num">{{ number_format($defect['occurrences']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">{{ __('No defects recorded — nothing has been scored yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card :title="__('Every scored ASIN')">
        <x-slot:actions>
            <a href="{{ route('tools.cdq-report.export-csv') }}" class="btn btn-sm"><x-icon name="csv" size="sm" />{{ __('Download work-queue CSV') }}</a>
        </x-slot:actions>
        @if ($parentRollup->contains(fn ($r) => ! $r['used_traffic_weighting']))
            <p class="muted text-sm">{{ __('Variation family image scores are an equal-weighted mean, not traffic-weighted — no glance-view data is loaded for this catalog yet.') }}</p>
        @endif
        @if ($scores->isEmpty())
            <x-empty-state :title="__('Nothing scored yet')" icon="check">{{ __('The weekly snapshot and scoring tasks run on Sundays.') }}</x-empty-state>
        @else
            <x-data-table caption="Every scored ASIN" copy="asin">
                <thead>
                    <tr>
                        <x-th sort column="asin">{{ __('ASIN') }}</x-th>
                        <x-th sort>{{ __('Title') }}</x-th>
                        <x-th sort>{{ __('Grade') }}</x-th>
                        <x-th sort type="number">{{ __('Score') }}</x-th>
                        <x-th sort type="number">{{ __('ATTR') }}</x-th>
                        <x-th sort type="number">{{ __('TEXT') }}</x-th>
                        <x-th sort type="number">{{ __('IMAGE') }}</x-th>
                        <x-th sort type="number">{{ __('APLUS') }}</x-th>
                        <x-th sort type="number">{{ __('CONSIST') }}</x-th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scores as $row)
                        <tr>
                            <td data-value="{{ $row['asin'] }}"><a href="{{ route('tools.cdq-report.show', $row['asin']) }}">{{ $row['asin'] }}</a></td>
                            <td class="truncate-cell-sm" title="{{ $row['title'] }}">{{ $row['title'] ?? '—' }}</td>
                            <td data-value="{{ $row['grade'] }}">@include('tools.cdq-report._grade', ['grade' => $row['grade']])</td>
                            <td class="num" data-value="{{ $row['total_score'] ?? '' }}">{{ $row['total_score'] !== null ? number_format($row['total_score'], 1) : '—' }}</td>
                            @foreach (['attr_score', 'text_score', 'image_score', 'aplus_score'] as $column)
                                <td class="num muted" data-value="{{ $row[$column] }}">{{ number_format($row[$column], 1) }}</td>
                            @endforeach
                            @if ($row['consist_checked'])
                                <td class="num muted" data-value="{{ $row['consist_score'] }}">{{ number_format($row['consist_score'], 1) }}</td>
                            @else
                                <td class="num" data-value="-1"><span class="muted text-xs">{{ __('Not checked') }}</span></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </x-data-table>
        @endif
    </x-card>

    @if ($scores->isNotEmpty())
        <script type="application/json" id="cdq-histogram-data">@json($scoreHistogram)</script>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const histogram = JSON.parse(document.getElementById('cdq-histogram-data').textContent);
                window.themedChart(document.getElementById('chart-histogram'), {
                    type: 'bar',
                    data: { labels: histogram.map((_, i) => `${i * 10}–${i * 10 + 9}`), datasets: [{ label: @js(__('ASINs')), data: histogram }] },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
                    },
                });
            });
        </script>
    @endif
</x-layouts.app>
