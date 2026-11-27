{{--
    A signal that needs attention, as on the design's home page.
    <x-incident-card tone="bad" source="Price Monitor" :count="39" label="ASINs priced below cost" meta="Scan · 12:07 AM" :href="..." />
--}}
@props(['tone' => 'warn', 'source', 'count', 'label', 'meta' => null, 'href' => null, 'action' => 'Review'])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} {{ $attributes->class('incident') }} @if ($href) href="{{ $href }}" @endif>
    <div class="flex items-center justify-between gap-2">
        <x-status-badge :tone="$tone" icon="alert">{{ $source }}</x-status-badge>
        @if ($meta)<span class="faint text-xs">{{ $meta }}</span>@endif
    </div>
    <div class="flex items-baseline gap-3">
        <span class="incident-count">{{ $count }}</span>
        <span class="muted font-medium">{{ $label }}</span>
    </div>
    @if ($href)<div class="text-sm font-semibold text-accent">{{ $action }} →</div>@endif
</{{ $tag }}>
