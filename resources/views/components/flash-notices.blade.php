{{-- Session flashes as notices that stay on the page (see <x-toasts> for when to use this). --}}
@foreach (['error' => 'bad', 'warning' => 'warn', 'status' => 'good'] as $key => $tone)
    @if (session()->has($key) && is_string(session($key)))
        <x-notice :tone="$tone" {{ $attributes }}>{{ session($key) }}</x-notice>
    @endif
@endforeach
