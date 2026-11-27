{{--
    A launcher tile: <x-tool-tile icon="box" label="PO Checker" :href="..." description="..." meta="..." />
    `icon` is an icon name or an emoji (<x-tool-icon>). Without an href the tile is not a link.
--}}
@props(['icon' => 'box', 'label', 'href' => null, 'description' => null, 'meta' => null])
<{{ $href ? 'a' : 'div' }} {{ $attributes->class('tile') }} @if ($href) href="{{ $href }}" @endif>
    <div class="flex items-center gap-3">
        <span class="tile-icon"><x-tool-icon :icon="$icon" size="lg" /></span>
        <span class="font-semibold">{{ $label }}</span>
    </div>
    @if ($description)<div class="muted text-sm">{{ $description }}</div>@endif
    @if ($meta)<div class="faint text-xs mt-auto">{{ $meta }}</div>@endif
</{{ $href ? 'a' : 'div' }}>
