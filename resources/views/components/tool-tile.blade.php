{{-- A launcher tile: <x-tool-tile icon="box" label="PO Checker" :href="..." description="..." meta="..." /> --}}
@props(['icon' => 'box', 'label', 'href', 'description' => null, 'meta' => null])
<a {{ $attributes->class('tile') }} href="{{ $href }}">
    <div class="flex items-center gap-3">
        <span class="tile-icon"><x-icon :name="$icon" size="lg" /></span>
        <span class="font-semibold">{{ $label }}</span>
    </div>
    @if ($description)<div class="muted text-sm">{{ $description }}</div>@endif
    @if ($meta)<div class="faint text-xs mt-auto">{{ $meta }}</div>@endif
</a>
