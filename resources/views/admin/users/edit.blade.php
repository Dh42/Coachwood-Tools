<x-layouts.app :title="$user->name" breadcrumb="Admin · Users">
    <x-slot:subtitle>{{ $user->email }} · {{ $user->google_id ? __('Signs in with Google') : __('Has not signed in yet') }}</x-slot:subtitle>

    <form method="post" action="{{ route('admin.users.update', $user) }}" class="flex flex-col gap-4">
        @csrf
        @method('put')

        <x-card :title="__('Role')">
            @php $isSelf = $user->is(auth()->user()); @endphp
            <div class="field">
                <label for="role">{{ __('Role') }}</label>
                <select id="role" name="role" class="select" @if ($errors->has('role')) aria-invalid="true" aria-describedby="role-error" @endif>
                    <option value="user" @selected(old('role', $userRole) === 'user')>{{ __('User') }}</option>
                    <option value="admin" @selected(old('role', $userRole) === 'admin')>{{ __('Admin') }}</option>
                </select>
                <p class="field-hint">{{ __('Admins can open every active tool and the admin pages. Users open only the tools ticked below.') }}</p>
                @if ($isSelf)
                    <p class="field-hint">{{ __('You can\'t remove your own admin role. Ask another admin.') }}</p>
                @endif
                @error('role')<p class="field-error" id="role-error"><x-icon name="alert" size="sm" />{{ $message }}</p>@enderror
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
                            <input type="checkbox" name="tools[]" value="{{ $tool->slug }}" @checked(in_array($tool->slug, old('tools', $userToolSlugs)))>
                            <x-tool-icon :icon="$tool->icon" />
                            <span>{{ $tool->name }}</span>
                            @unless ($tool->active)
                                <x-status-badge tone="mute">{{ __('Inactive') }}</x-status-badge>
                            @endunless
                        </label>
                    @endforeach
                </fieldset>
                <p class="field-hint">{{ __('An inactive tool is hidden from everyone. Access ticked here comes back when the tool is switched on again.') }}</p>
            @endif
        </x-card>

        <x-card :title="__('Teams')">
            @if ($teams->isEmpty())
                <p class="muted text-sm">{{ __('No teams yet.') }} <a href="{{ route('admin.teams.create') }}">{{ __('Create one') }}</a></p>
            @else
                <fieldset class="check-list">
                    <legend class="sr-only">{{ __('Teams') }}</legend>
                    @foreach ($teams as $team)
                        <label class="check">
                            <input type="checkbox" name="teams[]" value="{{ $team->id }}" @checked(in_array($team->id, old('teams', $userTeamIds)))>
                            <span>{{ $team->name }}</span>
                        </label>
                    @endforeach
                </fieldset>
            @endif
        </x-card>

        <div class="form-actions">
            <a href="{{ route('admin.users.index') }}" class="btn">{{ __('Cancel') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
        </div>
    </form>
</x-layouts.app>
