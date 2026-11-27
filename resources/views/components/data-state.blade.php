{{--
    The five states every data page handles (spec 15, rule 4). The slot is
    the complete view; the other states replace or annotate it.

    <x-data-state :state="$state" empty="No POs uploaded yet." :message="$error">
        ...table or chart...
    </x-data-state>

    state: loading | empty | error | partial | complete
--}}
@props(['state' => 'complete', 'empty' => 'No data for this period.', 'message' => null, 'rows' => 4])
@switch($state)
    @case('loading')
        <div {{ $attributes->class('flex flex-col gap-3') }} aria-busy="true">
            <span class="sr-only" role="status">Loading…</span>
            @for ($i = 0; $i < $rows; $i++)<span class="skeleton"></span>@endfor
        </div>
        @break
    @case('empty')
        <x-empty-state :title="$empty" {{ $attributes }}>{{ $message }}</x-empty-state>
        @break
    @case('error')
        <x-notice tone="bad" title="Couldn't load this." {{ $attributes }}>{{ $message ?? 'Try again shortly. If it keeps failing, send Lesley the page and time.' }}</x-notice>
        @break
    @case('partial')
        <div {{ $attributes->class('flex flex-col gap-3') }}>
            <x-notice tone="warn" title="Provisional or partial data.">{{ $message ?? 'Some of this period is still missing or may change.' }}</x-notice>
            {{ $slot }}
        </div>
        @break
    @default
        {{ $slot }}
@endswitch
