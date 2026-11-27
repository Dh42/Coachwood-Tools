{{-- <x-card title="..."> with optional <x-slot:actions> and <x-slot:footer>. --}}
@props(['title' => null])
<section {{ $attributes->class('card') }}>
    <div class="card-body">
        @if ($title || isset($actions))
            <div class="card-head">
                @if ($title)<h2 class="card-title">{{ $title }}</h2>@endif
                @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
            </div>
        @endif
        {{ $slot }}
        @isset($footer)<div class="card-head">{{ $footer }}</div>@endisset
    </div>
</section>
