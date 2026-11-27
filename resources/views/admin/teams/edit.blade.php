<x-layouts.app :title="$team->name" breadcrumb="Admin · Teams">
    <div class="flex flex-col gap-4">
        <x-card :title="__('Details')">
            <form method="post" action="{{ route('admin.teams.update', $team) }}" class="form-stack">
                @csrf
                @method('put')
                @include('admin.teams._form')
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                </div>
            </form>
        </x-card>

        <x-card :title="__('Members')">
            @if ($team->members->isEmpty())
                <p class="muted text-sm">{{ __('No members yet.') }}</p>
            @else
                <ul class="member-list">
                    @foreach ($team->members->sortBy('name') as $member)
                        <li>
                            <span>{{ $member->name }} <span class="muted">{{ $member->email }}</span></span>
                            <form method="post" action="{{ route('admin.teams.members.destroy', [$team, $member]) }}">
                                @csrf
                                @method('delete')
                                <button type="submit" class="btn btn-sm" aria-label="Remove {{ $member->name }}">{{ __('Remove') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($availableUsers->isNotEmpty())
                <form method="post" action="{{ route('admin.teams.members.store', $team) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <div class="field grow">
                        <label for="user_id">{{ __('Add a member') }}</label>
                        <select id="user_id" name="user_id" class="select">
                            @foreach ($availableUsers as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn">{{ __('Add') }}</button>
                </form>
            @endif
        </x-card>
    </div>
</x-layouts.app>
