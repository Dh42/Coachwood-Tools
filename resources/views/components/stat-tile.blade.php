{{--
    <x-stat-tile label="Units accepted · 90d" :value="$units" sub="232 POs" />
    A null value shows "No data" rather than a bare zero (spec 15, rule 4).
--}}
@props(['label', 'value' => null, 'sub' => null])
<div {{ $attributes->class('stat-tile') }}>
    <div class="label">{{ $label }}</div>
    @if ($value === null || $value === '')
        <div class="stat-value faint">No data</div>
    @else
        <div class="stat-value">{{ $value }}</div>
    @endif
    @if ($sub || isset($detail))<div class="stat-sub">{{ $detail ?? $sub }}</div>@endif
</div>
