{{-- Admin > Users > Invite, ported from the old app. Company-domain addresses only (decision 2026-11-27). --}}
<x-layouts.app title="Invite User" breadcrumb="Admin · Users">
    <x-slot:subtitle>
        {{ __('Sets up their access now and emails them a "Sign in with Google" link. Only addresses on :domains can be invited.', ['domains' => $domains === [] ? __('a company domain') : implode(', ', $domains)]) }}
    </x-slot:subtitle>

    @if ($domains === [])
        <x-notice tone="warn" class="mb-4">{{ __('No company domain is set up, so nobody can be invited yet.') }} <a href="{{ route('admin.google-domains.index') }}">{{ __('Add one in Google Sign-In.') }}</a></x-notice>
    @endif

    <form method="post" action="{{ route('admin.users.store') }}" class="flex flex-col gap-4">
        @csrf

        <x-card :title="__('Person')">
            <x-form-field name="name" :label="__('Name')" :value="old('name')" required autofocus maxlength="255" />
            <x-form-field name="email" type="email" :label="__('Email')" :value="old('email')" required maxlength="255" autocomplete="off" />
            <div class="field">
                <label for="role">{{ __('Role') }}</label>
                <select id="role" name="role" class="select">
                    <option value="user" @selected(old('role', 'user') === 'user')>{{ __('User') }}</option>
                    <option value="admin" @selected(old('role') === 'admin')>{{ __('Admin') }}</option>
                </select>
                <p class="field-hint">{{ __('Admins can open every active tool and the admin pages. Users open only the tools ticked below.') }}</p>
            </div>
        </x-card>

        <x-card :title="__('Tool access')">
            @if ($tools->isEmpty())
                <p class="muted text-sm">{{ __('No tools registered yet.') }}</p>
            @else
                <fieldset class="check-list">
                    <legend class="sr-only">{{ __('Tool access') }}</legend>
                    @foreach ($tools as $tool)
                        <label class="check">
                            <input type="checkbox" name="tools[]" value="{{ $tool->slug }}" @checked(in_array($tool->slug, old('tools', [])))>
                            <x-tool-icon :icon="$tool->icon" />
                            <span>{{ $tool->name }}</span>
                            @unless ($tool->active)
                                <x-status-badge tone="mute">{{ __('Inactive') }}</x-status-badge>
                            @endunless
                        </label>
                    @endforeach
                </fieldset>
            @endif
        </x-card>

        @if ($teams->isNotEmpty())
            <x-card :title="__('Teams')">
                <fieldset class="check-list">
                    <legend class="sr-only">{{ __('Teams') }}</legend>
                    @foreach ($teams as $team)
                        <label class="check">
                            <input type="checkbox" name="teams[]" value="{{ $team->id }}" @checked(in_array($team->id, old('teams', [])))>
                            <span>{{ $team->name }}</span>
                        </label>
                    @endforeach
                </fieldset>
            </x-card>
        @endif

        <div class="form-actions">
            <a href="{{ route('admin.users.index') }}" class="btn">{{ __('Cancel') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Send Invitation') }}</button>
        </div>
    </form>
</x-layouts.app>
