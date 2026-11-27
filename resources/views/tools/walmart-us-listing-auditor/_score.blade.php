{{-- A 0-100 Listing Quality score with its band in words, never colour alone (old bands: 80+, 50+, below). --}}
@if ($score === null)
    <span class="faint">—</span>
@else
    @php
        [$tone, $band] = match (true) {
            $score >= 80 => ['good', __('Good')],
            $score >= 50 => ['warn', __('Fair')],
            default => ['bad', __('Poor')],
        };
    @endphp
    <x-status-badge :tone="$tone" :icon="false" :title="$band">{{ number_format((float) $score, 1) }} · {{ $band }}</x-status-badge>
@endif
