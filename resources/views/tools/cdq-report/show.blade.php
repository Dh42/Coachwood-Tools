{{-- CDQ Listing Quality Report: one ASIN's latest snapshot, score and defects. --}}
<x-layouts.app title="CDQ Listing Quality Report" breadcrumb="Amazon · CDQ Listing Quality Report" :heading="$snapshot->asin">
    <x-slot:subtitle>{{ $title ?? __('(no title on file)') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('tools.cdq-report.index') }}" class="btn">{{ __('All ASINs') }}</a>
        @if ($score)
            @include('tools.cdq-report._grade', ['grade' => $score->grade, 'prefix' => __('Grade').' '])
        @endif
    </x-slot:actions>

    @if ($score === null)
        <x-card>
            <x-empty-state :title="__('Not scored yet')" icon="check">{{ __("This ASIN hasn't been scored under the current check-config revision yet.") }}</x-empty-state>
        </x-card>
    @else
        <x-stat-row class="stat-row-tight">
            <x-stat-tile :label="__('Total')" :value="$score->total_score !== null ? number_format($score->total_score, 1) : null" />
            <x-stat-tile :label="__('ATTR')" :value="number_format($score->attr_score, 1)" />
            <x-stat-tile :label="__('TEXT')" :value="number_format($score->text_score, 1)" />
            <x-stat-tile :label="__('IMAGE')" :value="number_format($score->image_score, 1)" />
            <x-stat-tile :label="__('APLUS')" :value="number_format($score->aplus_score, 1)" />
            @if ($score->consist_checked_at)
                <x-stat-tile :label="__('CONSIST')" :value="number_format($score->consist_score, 1)" />
            @else
                <x-stat-tile :label="__('CONSIST')" :value="__('Not checked')" :sub="__('Counted as :n in the total until the check runs', ['n' => number_format($score->consist_score, 1)])" />
            @endif
        </x-stat-row>
    @endif

    <x-card :title="__('Defects on this snapshot')">
        @if ($defects->isEmpty())
            <x-empty-state :title="__('No defects')" icon="check">{{ __('No defects — clean listing under the current checks.') }}</x-empty-state>
        @else
            <x-data-table caption="Defects on this snapshot">
                <thead>
                    <tr>
                        <x-th sort>{{ __('Check') }}</x-th>
                        <x-th sort>{{ __('Dimension') }}</x-th>
                        <x-th sort>{{ __('Severity') }}</x-th>
                        <x-th sort type="number">{{ __('Points') }}</x-th>
                        <x-th sort>{{ __('Attribute path') }}</x-th>
                        <x-th>{{ __('Detail') }}</x-th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($defects as $defect)
                        @php
                            [$tone, $icon] = match ($defect->severity) {
                                'CRITICAL' => ['bad', 'alert'],
                                'ERROR' => ['warn', 'alert'],
                                'WARN' => ['mute', 'info'],
                                default => ['info', 'info'],
                            };
                        @endphp
                        <tr>
                            <td><code>{{ $defect->check_code }}</code></td>
                            <td class="muted">{{ $defect->dimension }}</td>
                            <td data-value="{{ $defect->severity }}"><x-status-badge :tone="$tone" :icon="$icon">{{ $defect->severity }}</x-status-badge></td>
                            <td class="num" data-value="{{ $defect->points }}">{{ number_format($defect->points, 1) }}</td>
                            <td class="wrap-cell">{{ $defect->attribute_path ?? '—' }}</td>
                            <td class="wrap-cell muted text-xs">@if ($defect->detail_json)<code>{{ json_encode($defect->detail_json) }}</code>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-data-table>
        @endif
    </x-card>

    <p class="muted text-sm">
        {{ __('Snapshot captured :date · content hash :hash', ['date' => $snapshot->captured_at->format('Y-m-d H:i'), 'hash' => substr($snapshot->content_hash, 0, 12)]) }}
    </p>
</x-layouts.app>
