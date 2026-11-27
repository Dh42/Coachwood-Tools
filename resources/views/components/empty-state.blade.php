@props(['title' => 'Nothing here yet', 'icon' => 'inbox'])
<div {{ $attributes->class('empty-state') }}>
    <x-icon :name="$icon" />
    <div class="empty-title">{{ $title }}</div>
    @if (trim($slot) !== '')<div>{{ $slot }}</div>@endif
    @isset($actions)<div class="flex flex-wrap justify-center gap-2">{{ $actions }}</div>@endisset
</div>
