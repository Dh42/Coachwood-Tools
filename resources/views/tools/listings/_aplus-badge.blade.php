{{-- An A+ Content status as a labelled badge: used in the table's A+ column and in the key that explains it. --}}
@switch ($status ?? null)
    @case('full')
        <x-status-badge tone="good">{{ __('A+') }}</x-status-badge>
        @break
    @case('brand_only')
        <x-status-badge tone="warn">{{ __('Brand only') }}</x-status-badge>
        @break
    @case('none')
        <x-status-badge tone="bad">{{ __('None') }}</x-status-badge>
        @break
    @default
        <span class="muted">—</span>
@endswitch
