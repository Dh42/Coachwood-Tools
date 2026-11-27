<x-card :title="__('Delete Account')">
    <p class="muted text-sm">
        {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
    </p>

    <div>
        <button type="button" class="btn btn-danger" data-modal-open="confirm-user-deletion">{{ __('Delete Account') }}</button>
    </div>

    <x-modal id="confirm-user-deletion" :title="__('Are you sure you want to delete your account?')" :data-modal-show="$errors->userDeletion->isNotEmpty()">
        <form method="post" action="{{ route('profile.destroy') }}" class="form-stack" id="delete-user-form">
            @csrf
            @method('delete')

            @if ($user->password)
                <p class="muted text-sm">
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </p>
                <x-form-field id="delete_user_password" name="password" type="password" bag="userDeletion" :label="__('Password')" autocomplete="current-password" />
            @else
                <p class="muted text-sm">
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. This action cannot be undone.') }}
                </p>
            @endif
        </form>

        <x-slot:footer>
            <button type="button" class="btn" data-modal-close>{{ __('Cancel') }}</button>
            <button type="submit" class="btn btn-danger" form="delete-user-form">{{ __('Delete Account') }}</button>
        </x-slot:footer>
    </x-modal>
</x-card>
