<x-card :title="__('Update Password')">
    <p class="muted text-sm">{{ __('Ensure your account is using a long, random password to stay secure.') }}</p>

    <form method="post" action="{{ route('password.update') }}" class="form-stack">
        @csrf
        @method('put')

        <x-form-field id="update_password_current_password" name="current_password" type="password" bag="updatePassword" :label="__('Current Password')" autocomplete="current-password" />
        <x-form-field id="update_password_password" name="password" type="password" bag="updatePassword" :label="__('New Password')" autocomplete="new-password" />
        <x-form-field id="update_password_password_confirmation" name="password_confirmation" type="password" bag="updatePassword" :label="__('Confirm Password')" autocomplete="new-password" />

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </form>
</x-card>
