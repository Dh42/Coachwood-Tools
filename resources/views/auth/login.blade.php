{{-- Sign-in is Google only (decision 2026-11-27). --}}
<x-layouts.guest title="Sign in">
    <div class="flex flex-col gap-2 text-center">
        <h1 class="section-title">{{ __('Sign in') }}</h1>
        <p class="muted text-sm">{{ __('Sign in to access your :app dashboard.', ['app' => config('app.name')]) }}</p>
    </div>

    @if ($errors->any())
        <x-notice tone="bad">{{ $errors->first() }}</x-notice>
    @endif

    <a href="{{ route('google.redirect') }}" class="btn btn-primary w-full">
        <img src="{{ asset('images/google-g.svg') }}" alt="" class="icon">
        {{ __('Sign in with Google') }}
    </a>

    <p class="muted text-xs text-center">{{ __('Use your company Google account.') }}</p>
</x-layouts.guest>
