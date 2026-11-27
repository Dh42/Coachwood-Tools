{{--
    The primary surface for data (spec 15, rule 6): sortable, filterable,
    CSV export, and "copy ASINs" where a tool has it. Behaviour is in
    resources/js/data-table.js; the server renders every row.

    <x-data-table caption="Open POs" csv="open-pos.csv" copy="asin" copy-label="Copy ASINs">
        <thead><tr><x-th sort column="asin">ASIN</x-th><x-th sort type="number">Units</x-th></tr></thead>
        <tbody>...</tbody>
    </x-data-table>

    Wrap it in <x-data-state> for the loading, empty, error and partial states.
--}}
@props(['caption' => null, 'csv' => null, 'filter' => true, 'copy' => null, 'copyLabel' => 'Copy ASINs', 'copyNoun' => 'ASINs'])
<div {{ $attributes->class('flex flex-col gap-3') }} data-table>
    @if ($filter || $csv || $copy || isset($tools))
        <div class="table-tools">
            <div class="flex flex-wrap items-center gap-2">
                @if ($filter)
                    <label class="sr-only" for="{{ $filterId = 'filter-'.\Illuminate\Support\Str::random(6) }}">Filter rows</label>
                    <input id="{{ $filterId }}" class="input" type="search" placeholder="Filter rows" data-table-filter>
                    <span class="label" data-table-status aria-live="polite"></span>
                @endif
                @isset($tools){{ $tools }}@endisset
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($copy)
                    <button type="button" class="btn btn-sm" data-table-copy data-column="{{ $copy }}" data-noun="{{ $copyNoun }}"><x-icon name="copy" size="sm" />{{ $copyLabel }}</button>
                @endif
                @if ($csv)
                    <button type="button" class="btn btn-sm" data-table-csv data-filename="{{ $csv }}"><x-icon name="download" size="sm" />Export CSV</button>
                @endif
            </div>
        </div>
    @endif
    <div class="table-wrap">
        <table class="table">
            @if ($caption)<caption class="sr-only">{{ $caption }}</caption>@endif
            {{ $slot }}
        </table>
    </div>
</div>
