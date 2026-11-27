{{-- A CDQ grade as a labelled badge. The letter is the cue; the tone adds to it. --}}
@php
    [$tone, $icon] = match ($grade) {
        'A', 'B' => ['good', 'check'],
        'C' => ['warn', 'alert'],
        'D' => ['bad', 'alert'],
        default => ['mute', 'info'],
    };
@endphp
<x-status-badge :tone="$tone" :icon="$icon">{{ $prefix ?? '' }}{{ $grade ?? '—' }}</x-status-badge>
