<x-layouts.guest title="Sign in">
    <h1 class="section-title">{{ __('Sign in') }}</h1>

    @if (session('status'))
        <x-notice tone="good">{{ session('status') }}</x-notice>
    @endif

    @if (Route::has('google.redirect'))
        @include('auth.partials.google-button')
        <div class="divider"><span>{{ __('or') }}</span></div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="form-stack">
        @csrf

        <x-form-field name="email" type="email" :label="__('Email')" :value="old('email')" required autofocus autocomplete="username" />
        <x-form-field name="password" type="password" :label="__('Password')" required autocomplete="current-password" />

        <label class="check">
            <input id="remember_me" type="checkbox" name="remember">
            <span>{{ __('Remember me') }}</span>
        </label>

        <div class="form-actions">
            <a class="text-sm muted" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Log in') }}</button>
        </div>
    </form>
</x-layouts.guest>
