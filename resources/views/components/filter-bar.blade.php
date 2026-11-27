{{--
    A GET form of filters that keeps the URL shareable.
    <x-filter-bar :action="route('...')"> <label class="field"><span>From</span><input class="input" type="date" name="from"></label> </x-filter-bar>
--}}
@props(['action' => null, 'method' => 'GET', 'submit' => 'Apply'])
<form {{ $attributes->class('filter-bar') }} method="{{ $method }}" action="{{ $action ?? url()->current() }}" role="search">
    {{ $slot }}
    @if ($submit)<button type="submit" class="btn">{{ $submit }}</button>@endif
    @isset($actions){{ $actions }}@endisset
</form>
