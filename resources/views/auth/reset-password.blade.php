<x-layouts.guest title="Choose a new password">
    <h1 class="section-title">{{ __('Choose a new password') }}</h1>

    <form method="POST" action="{{ route('password.store') }}" class="form-stack">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-form-field name="email" type="email" :label="__('Email')" :value="old('email', $request->email)" required autofocus autocomplete="username" />
        <x-form-field name="password" type="password" :label="__('Password')" required autocomplete="new-password" />
        <x-form-field name="password_confirmation" type="password" :label="__('Confirm Password')" required autocomplete="new-password" />

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('Reset Password') }}</button>
        </div>
    </form>
</x-layouts.guest>
