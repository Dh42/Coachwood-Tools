<x-layouts.guest title="Reset your password">
    <h1 class="section-title">{{ __('Reset your password') }}</h1>
    <p class="muted text-sm">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
    </p>

    @if (session('status'))
        <x-notice tone="good">{{ session('status') }}</x-notice>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="form-stack">
        @csrf

        <x-form-field name="email" type="email" :label="__('Email')" :value="old('email')" required autofocus autocomplete="username" />

        <div class="form-actions">
            <a class="text-sm muted" href="{{ route('login') }}">{{ __('Back to sign in') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Email Password Reset Link') }}</button>
        </div>
    </form>
</x-layouts.guest>
