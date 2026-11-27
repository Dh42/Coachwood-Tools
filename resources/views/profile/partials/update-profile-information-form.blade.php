<x-card :title="__('Profile Information')">
    <p class="muted text-sm">{{ __("Update your account's profile information and email address.") }}</p>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="form-stack">
        @csrf
        @method('patch')

        <x-form-field name="name" :label="__('Name')" :value="old('name', $user->name)" required autocomplete="name" />
        <x-form-field name="email" type="email" :label="__('Email')" :value="old('email', $user->email)" required autocomplete="username" />

        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
            <p class="text-sm">
                {{ __('Your email address is unverified.') }}
                <button form="send-verification" class="link-btn">{{ __('Click here to re-send the verification email.') }}</button>
            </p>
        @endif

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </form>
</x-card>
