{{-- A header cell for <x-data-table>. `sort` makes it sortable; type="number" sorts numerically; column names it for "copy". --}}
@props(['sort' => false, 'type' => 'text', 'column' => null])
<th {{ $attributes->merge(['scope' => 'col']) }} @if ($sort) data-sort @endif data-type="{{ $type }}" @if ($column) data-column="{{ $column }}" @endif>
    @if ($sort)<button type="button" class="sort-btn">{{ $slot }}</button>@else{{ $slot }}@endif
</th>
