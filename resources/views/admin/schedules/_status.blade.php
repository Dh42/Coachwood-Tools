{{-- A run's status badge: words and an icon, never colour alone. --}}
@php
    $badge = match ($run?->status()) {
        'success' => ['good', __('Succeeded')],
        'failed' => ['bad', __('Failed')],
        'running' => ['info', __('Running')],
        'stale' => ['warn', __('Stopped without finishing')],
        default => ['mute', __('Never run')],
    };
@endphp
<x-status-badge :tone="$badge[0]">{{ $badge[1] }}</x-status-badge>
