{{--
    A status always carries words, never colour alone (spec 15, rule 7).
    <x-status-badge tone="bad">Below cost</x-status-badge>
    Tones: good, warn, near, bad, gap, info, mute, accent. :icon="false" hides the icon.
--}}
@props(['tone' => 'mute', 'icon' => null])
@php
    $icons = ['good' => 'check', 'bad' => 'alert', 'warn' => 'alert', 'near' => 'alert', 'info' => 'info', 'gap' => 'map', 'mute' => 'clock', 'accent' => 'star'];
    $iconName = $icon === false ? null : ($icon ?? $icons[$tone] ?? null);
@endphp
<span {{ $attributes->class(['tone', 'tone-'.$tone]) }}>@if ($iconName)<x-icon :name="$iconName" size="sm" />@endif{{ $slot }}</span>
