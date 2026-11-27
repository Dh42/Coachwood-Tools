{{--
    A tool's icon: an App\View\Icons name draws that icon; anything else (the
    old app's tools use emoji) is shown as text. Decorative either way, as the
    tool's name is always next to it.
    <x-tool-icon :icon="$tool->icon" size="lg" />
--}}
@props(['icon' => null, 'size' => 'md'])
@if (\App\View\Icons::has($icon))
    <x-icon :name="$icon" :size="$size" />
@elseif (filled($icon))
    <span class="tool-emoji" aria-hidden="true">{{ $icon }}</span>
@else
    <x-icon name="box" :size="$size" />
@endif
