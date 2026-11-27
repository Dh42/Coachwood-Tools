<x-layouts.app title="Profile" breadcrumb="Account">
    <div class="flex flex-col gap-4">
        @include('profile.partials.update-profile-information-form')

        @if ($user->password)
            @include('profile.partials.update-password-form')
        @else
            <x-card :title="__('Update Password')">
                <x-notice tone="info">{{ __('Your password is managed by your Google account.') }}</x-notice>
            </x-card>
        @endif

        @include('profile.partials.delete-user-form')
    </div>
</x-layouts.app>
