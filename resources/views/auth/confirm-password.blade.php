<x-layouts.guest title="Confirm your password">
    <h1 class="section-title">{{ __('Confirm your password') }}</h1>
    <p class="muted text-sm">
        {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
    </p>

    <form method="POST" action="{{ route('password.confirm') }}" class="form-stack">
        @csrf

        <x-form-field name="password" type="password" :label="__('Password')" required autofocus autocomplete="current-password" />

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('Confirm') }}</button>
        </div>
    </form>
</x-layouts.guest>
