<x-layouts.app title="Profile" breadcrumb="Account">
    <div class="flex flex-col gap-4">
        <x-card :title="__('Profile Information')">
            <form method="post" action="{{ route('profile.update') }}" class="form-stack">
                @csrf
                @method('patch')

                <x-form-field name="name" :label="__('Name')" :value="old('name', $user->name)" required autocomplete="name" />
                <x-form-field name="email" type="email" :label="__('Email')" :value="$user->email" readonly
                              :hint="__('Your email comes from your Google account.')" />

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                </div>
            </form>
        </x-card>

        <x-card :title="__('Sign-in')">
            <x-notice tone="info">{{ __('Your password is managed by your Google account.') }}</x-notice>
        </x-card>
    </div>
</x-layouts.app>
