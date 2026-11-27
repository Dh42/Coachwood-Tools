{{-- The signed-out landing page (the old app's welcome.blade.php). The Google button returns in step 3f. --}}
<x-layouts.guest title="Sign in">
    <div class="flex flex-col gap-2 text-center">
        <h1 class="section-title">{{ __('Sign in') }}</h1>
        <p class="muted text-sm">{{ __('Sign in to access your :app dashboard.', ['app' => config('app.name')]) }}</p>
    </div>

    @include('auth.partials.google-button')

    <a href="{{ route('login') }}" class="btn {{ Route::has('google.redirect') ? '' : 'btn-primary' }} w-full">
        {{ Route::has('google.redirect') ? __('Sign in with a password instead') : __('Sign in with a password') }}
    </a>
</x-layouts.guest>
