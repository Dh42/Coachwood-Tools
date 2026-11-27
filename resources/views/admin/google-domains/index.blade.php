{{-- Admin > Google Sign-In, ported from the old app. Sign-in is Google only (decision 2026-11-27). --}}
<x-layouts.app title="Google Sign-In" breadcrumb="Admin · Settings">
    <x-slot:subtitle>The company Google Workspace domains whose accounts may sign in. Google sign-in is the only way in.</x-slot:subtitle>

    <div class="flex flex-col gap-4">
        @if ($domains->isEmpty())
            <x-notice tone="bad" title="Nobody can sign in with Google.">
                {{ __('No domain is set up, so every sign-in is refused, admins included. Add your company domain below. People already signed in stay signed in.') }}
            </x-notice>
        @endif

        <x-card :title="__('Allowed domains')">
            <p class="muted text-sm">
                {{ __('A Google Workspace account on one of these domains can sign in. New people get the user role and no tools until an admin grants them. Personal Google accounts are always refused.') }}
            </p>

            @if ($domains->isNotEmpty())
                <ul class="member-list">
                    @foreach ($domains as $domain)
                        <li>
                            <span class="font-semibold">{{ $domain->domain }}</span>
                            <button type="button" class="btn btn-sm" data-modal-open="remove-domain-{{ $domain->id }}" aria-label="Remove {{ $domain->domain }}">{{ __('Remove') }}</button>

                            <x-modal id="remove-domain-{{ $domain->id }}" :title="__('Remove :domain?', ['domain' => $domain->domain])">
                                @if ($domains->count() === 1)
                                    <x-notice tone="bad" :title="__('This is the last domain.')">
                                        {{ __('Removing it means nobody can sign in with Google, you included, until a domain is added again. People already signed in stay signed in.') }}
                                    </x-notice>
                                @else
                                    <p class="text-sm">{{ __('People with a :domain account will no longer be able to sign in.', ['domain' => $domain->domain]) }}</p>
                                @endif
                                <x-slot:footer>
                                    <button type="button" class="btn" data-modal-close>{{ __('Cancel') }}</button>
                                    <form method="post" action="{{ route('admin.google-domains.destroy', $domain) }}">
                                        @csrf
                                        @method('delete')
                                        <button type="submit" class="btn btn-danger">{{ __('Remove Domain') }}</button>
                                    </form>
                                </x-slot:footer>
                            </x-modal>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="post" action="{{ route('admin.google-domains.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div class="grow">
                    <x-form-field name="domain" :label="__('Add a domain')" :value="old('domain')" placeholder="example.com" required maxlength="255" autocomplete="off" />
                </div>
                <button type="submit" class="btn btn-primary">{{ __('Add Domain') }}</button>
            </form>
        </x-card>
    </div>
</x-layouts.app>
