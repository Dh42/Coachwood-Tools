{{--
    Link tabs: each tab is a URL, so tabs survive reloads and can be shared.
    <x-tabs :tabs="['overview' => 'Overview', 'by-asin' => 'By ASIN']" :active="$tab" param="tab" />
--}}
@props(['tabs', 'active' => null, 'param' => 'tab', 'label' => 'Sections'])
@php $active ??= array_key_first($tabs); @endphp
<nav {{ $attributes->class('tabs') }} aria-label="{{ $label }}">
    @foreach ($tabs as $key => $tabLabel)
        <a class="tab" href="{{ request()->fullUrlWithQuery([$param => $key]) }}" @if ((string) $key === (string) $active) aria-current="page" @endif>{{ $tabLabel }}</a>
    @endforeach
</nav>
