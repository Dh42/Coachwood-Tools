{{--
    Admin > Tools, ported from the old app. Rows reorder by dragging the
    handle (SortableJS, as before) or with the Move up / Move down buttons,
    which also work from the keyboard. Either way the whole order is saved.
--}}
<x-layouts.app title="Tools" breadcrumb="Admin">
    <x-slot:subtitle>The launcher and sidebar show active tools in this order, to people with access.</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('admin.tools.create') }}" class="btn btn-primary">{{ __('New Tool') }}</a>
    </x-slot:actions>

    <x-card>
        @if ($tools->isEmpty())
            <x-empty-state title="No tools registered yet" icon="box">
                Each tool registers itself when it is ported. You can also add one with New Tool.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table" data-tools-table data-reorder-url="{{ route('admin.tools.reorder') }}">
                    <thead>
                        <tr>
                            <th scope="col"><span class="sr-only">{{ __('Order') }}</span></th>
                            <th scope="col">{{ __('Name') }}</th>
                            <th scope="col">{{ __('Group') }}</th>
                            <th scope="col">{{ __('Slug') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tools as $tool)
                            <tr data-tool-id="{{ $tool->id }}">
                                <td>
                                    <span class="drag-handle" title="{{ __('Drag to reorder') }}" aria-hidden="true"><x-icon name="grip" /></span>
                                    <button type="button" class="icon-btn icon-btn-sm" data-move="up" aria-label="Move {{ $tool->name }} up"><x-icon name="chevron-up" size="sm" /></button>
                                    <button type="button" class="icon-btn icon-btn-sm" data-move="down" aria-label="Move {{ $tool->name }} down"><x-icon name="chevron-down" size="sm" /></button>
                                </td>
                                <td>
                                    <span class="flex items-center gap-2"><x-tool-icon :icon="$tool->icon" />{{ $tool->name }}</span>
                                </td>
                                <td>{{ $tool->group ?: '—' }}</td>
                                <td><code>{{ $tool->slug }}</code></td>
                                <td>
                                    @if ($tool->active)
                                        <x-status-badge tone="good">{{ __('Active') }}</x-status-badge>
                                    @else
                                        <x-status-badge tone="mute">{{ __('Inactive') }}</x-status-badge>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('admin.tools.edit', $tool) }}" class="btn btn-sm">{{ __('Edit') }}</a>
                                    <button type="button" class="btn btn-sm" data-modal-open="delete-tool-{{ $tool->id }}">{{ __('Delete') }}</button>

                                    <x-modal id="delete-tool-{{ $tool->id }}" :title="__('Delete :name?', ['name' => $tool->name])" class="text-left">
                                        <p class="text-sm">{{ __('This removes the tool and its permission, revoking access for everyone who currently has it. This cannot be undone.') }}</p>
                                        <x-slot:footer>
                                            <button type="button" class="btn" data-modal-close>{{ __('Cancel') }}</button>
                                            <form method="post" action="{{ route('admin.tools.destroy', $tool) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-danger">{{ __('Delete Tool') }}</button>
                                            </form>
                                        </x-slot:footer>
                                    </x-modal>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="sr-only" aria-live="polite" data-reorder-status></p>
        @endif
    </x-card>

    @vite('resources/js/admin-tools.js')
</x-layouts.app>
