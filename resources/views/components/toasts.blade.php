{{--
    The toast region, with session flashes: status (good), warning (warn) and error (bad).
    A page whose messages are too long to read in a toast passes :flash-inline="true" to
    <x-layouts.app> and shows them itself with <x-flash-notices />; then none are toasted here.
--}}
@props(['inline' => false])
<div class="toasts" data-toasts aria-live="polite">
    @unless ($inline)
        @foreach (['status' => 'good', 'warning' => 'warn', 'error' => 'bad'] as $key => $tone)
            @if (session()->has($key) && is_string(session($key)))
                <template data-toast="{{ $tone }}">{{ session($key) }}</template>
            @endif
        @endforeach
    @endunless
</div>
