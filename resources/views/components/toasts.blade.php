{{-- The toast region, with session flashes: status (good), warning (warn) and error (bad). --}}
<div class="toasts" data-toasts aria-live="polite">
    @foreach (['status' => 'good', 'warning' => 'warn', 'error' => 'bad'] as $key => $tone)
        @if (session()->has($key) && is_string(session($key)))
            <template data-toast="{{ $tone }}">{{ session($key) }}</template>
        @endif
    @endforeach
</div>
