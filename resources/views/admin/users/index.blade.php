{{-- Admin > Users, ported from the old app. --}}
<x-layouts.app title="Users" breadcrumb="Admin">
    <x-slot:subtitle>People who have signed in or been invited. Deactivate to remove access; delete only after deactivating.</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">{{ __('Invite User') }}</a>
    </x-slot:actions>

    <x-card>
        <x-data-table caption="Users">
            <thead>
                <tr>
                    <x-th sort>{{ __('Name') }}</x-th>
                    <x-th sort>{{ __('Email') }}</x-th>
                    <x-th sort>{{ __('Role') }}</x-th>
                    <x-th sort>{{ __('Status') }}</x-th>
                    <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->name }}@if ($user->is(auth()->user())) <span class="muted">({{ __('you') }})</span>@endif</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ ucfirst($user->roles->pluck('name')->implode(', ')) ?: '—' }}</td>
                        <td>
                            @if ($user->isDisabled())
                                <x-status-badge tone="bad">{{ __('Deactivated') }}</x-status-badge>
                            @elseif ($user->isPending())
                                <x-status-badge tone="warn">{{ __('Not signed in yet') }}</x-status-badge>
                            @else
                                <x-status-badge tone="good">{{ __('Active') }}</x-status-badge>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm">{{ __('Edit') }}</a>
                                @if ($user->isPending() && ! $user->isDisabled())
                                    <form method="post" action="{{ route('admin.users.resend-invitation', $user) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm">{{ __('Resend invite') }}</button>
                                    </form>
                                @endif
                                @unless ($user->is(auth()->user()))
                                    @if ($user->isDisabled())
                                        <form method="post" action="{{ route('admin.users.activate', $user) }}">
                                            @csrf
                                            @method('patch')
                                            <button type="submit" class="btn btn-sm">{{ __('Activate') }}</button>
                                        </form>
                                        <button type="button" class="btn btn-sm" data-modal-open="delete-user-{{ $user->id }}">{{ __('Delete') }}</button>
                                    @else
                                        <button type="button" class="btn btn-sm" data-modal-open="deactivate-user-{{ $user->id }}">{{ __('Deactivate') }}</button>
                                    @endif
                                @endunless
                            </div>

                            @unless ($user->is(auth()->user()))
                                @if ($user->isDisabled())
                                    <x-modal id="delete-user-{{ $user->id }}" :title="__('Permanently delete :name?', ['name' => $user->name])" class="text-left">
                                        <p class="text-sm">{{ __('This cannot be undone. Any records they made, such as PO overrides or listing edits, will lose their "changed by" name.') }}</p>
                                        <x-slot:footer>
                                            <button type="button" class="btn" data-modal-close>{{ __('Cancel') }}</button>
                                            <form method="post" action="{{ route('admin.users.destroy', $user) }}">
                                                @csrf
                                                @method('delete')
                                                <button type="submit" class="btn btn-danger">{{ __('Delete User') }}</button>
                                            </form>
                                        </x-slot:footer>
                                    </x-modal>
                                @else
                                    <x-modal id="deactivate-user-{{ $user->id }}" :title="__('Deactivate :name?', ['name' => $user->name])" class="text-left">
                                        <p class="text-sm">{{ __('They are signed out straight away and can\'t sign in again until an admin activates them. Their access settings are kept.') }}</p>
                                        <x-slot:footer>
                                            <button type="button" class="btn" data-modal-close>{{ __('Cancel') }}</button>
                                            <form method="post" action="{{ route('admin.users.deactivate', $user) }}">
                                                @csrf
                                                @method('patch')
                                                <button type="submit" class="btn btn-danger">{{ __('Deactivate') }}</button>
                                            </form>
                                        </x-slot:footer>
                                    </x-modal>
                                @endif
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-data-table>
    </x-card>
</x-layouts.app>
