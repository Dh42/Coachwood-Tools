@php
    $catalogBadge = ['vc' => [__('VC only'), 'mute'], 'sc' => [__('SC only'), 'info'], 'both' => [__('Both'), 'accent']];
    $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1).'%';
@endphp
<x-card>
    <x-data-table caption="ASINs" csv="us-product-reviews.csv" copy="asin">
        <thead>
            <tr>
                <x-th sort column="asin">{{ __('ASIN') }}</x-th>
                <x-th sort>{{ __('Title') }}</x-th>
                <x-th sort>{{ __('Catalog') }}</x-th>
                <x-th sort>{{ __('Status') }}</x-th>
                <x-th sort type="number">{{ __('Positive topics') }}</x-th>
                <x-th sort type="number">{{ __('Negative topics') }}</x-th>
                <x-th>{{ __('Top positive topic') }}</x-th>
                <x-th>{{ __('Top negative topic') }}</x-th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                @php [$badgeLabel, $badgeTone] = $catalogBadge[$r['source_catalog']] ?? ['?', 'mute']; @endphp
                <tr>
                    <td data-value="{{ $r['asin'] }}"><a href="{{ route('tools.us-product-reviews.index', ['asin' => $r['asin']]) }}">{{ $r['asin'] }}</a></td>
                    <td class="truncate-cell">{{ $r['title'] ?? '—' }}</td>
                    <td><x-status-badge :tone="$badgeTone" :icon="false">{{ $badgeLabel }}</x-status-badge></td>
                    <td>
                        @if ($r['http_status'] == 200)
                            <x-status-badge tone="good">{{ __('Has data') }}</x-status-badge>
                        @elseif ($r['http_status'] == 204)
                            <x-status-badge tone="mute">{{ __('No content') }}</x-status-badge>
                        @else
                            <x-status-badge tone="bad">{{ __('HTTP :status', ['status' => $r['http_status']]) }}</x-status-badge>
                        @endif
                    </td>
                    <td class="num">{{ (int) $r['positive_count'] }}</td>
                    <td class="num">{{ (int) $r['negative_count'] }}</td>
                    <td class="wrap-cell">
                        @if ($r['top_positive_topic'])
                            {{ $r['top_positive_topic']->topic }} <span class="faint">({{ $pct((float) $r['top_positive_topic']->occurrence_percentage) }}, {{ (int) $r['top_positive_topic']->number_of_mentions }} {{ __('mentions') }})</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="wrap-cell">
                        @if ($r['top_negative_topic'])
                            {{ $r['top_negative_topic']->topic }} <span class="faint">({{ $pct((float) $r['top_negative_topic']->occurrence_percentage) }}, {{ (int) $r['top_negative_topic']->number_of_mentions }} {{ __('mentions') }})</span>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">{{ __('No ASINs pulled yet.') }}</td></tr>
            @endforelse
        </tbody>
    </x-data-table>
</x-card>
