<x-layouts.app title="Teams" breadcrumb="Admin">
    <x-slot:subtitle>Groups of people. No tool uses teams yet.</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('admin.teams.create') }}" class="btn btn-primary">{{ __('New Team') }}</a>
    </x-slot:actions>

    <x-card>
        @if ($teams->isEmpty())
            <x-empty-state title="No teams yet" icon="users">{{ __('Create a team to group people together.') }}</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Name') }}</th>
                            <th scope="col">{{ __('Description') }}</th>
                            <th scope="col" class="num">{{ __('Members') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($teams as $team)
                            <tr>
                                <td>{{ $team->name }}</td>
                                <td class="muted">{{ $team->description ?: '—' }}</td>
                                <td class="num">{{ $team->members_count }}</td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('admin.teams.edit', $team) }}" class="btn btn-sm">{{ __('Edit') }}</a>
                                        <button type="button" class="btn btn-sm" data-modal-open="delete-team-{{ $team->id }}">{{ __('Delete') }}</button>
                                    </div>
                                    <x-modal id="delete-team-{{ $team->id }}" :title="__('Delete :name?', ['name' => $team->name])" class="text-left">
                                        <p class="text-sm">{{ __('The team and its member list are removed. The people themselves are not affected.') }}</p>
                                        <x-slot:footer>
                                            <button type="button" class="btn" data-modal-close>{{ __('Cancel') }}</button>
                                            <form method="post" action="{{ route('admin.teams.destroy', $team) }}">
                                                @csrf
                                                @method('delete')
                                                <button type="submit" class="btn btn-danger">{{ __('Delete Team') }}</button>
                                            </form>
                                        </x-slot:footer>
                                    </x-modal>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-layouts.app>
