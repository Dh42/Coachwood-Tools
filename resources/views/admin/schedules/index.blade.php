{{-- Admin > Scheduler, ported from the old app. Times are UTC unless a task has its own timezone. --}}
<x-layouts.app title="Scheduler" breadcrumb="Admin">
    <x-slot:subtitle>Background tasks. The server runs the scheduler every minute; each enabled task runs on its own schedule.</x-slot:subtitle>

    <x-card>
        @if ($tasks->isEmpty())
            <x-empty-state title="No scheduled tasks" icon="calendar">Tools register their tasks when they are ported.</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Task') }}</th>
                            <th scope="col">{{ __('Schedule') }}</th>
                            <th scope="col">{{ __('Last run') }}</th>
                            <th scope="col">{{ __('Next run') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.schedules.show', $task) }}" class="font-semibold">{{ $task->name }}</a>
                                    <div class="faint text-xs"><code>{{ $task->command }}</code></div>
                                </td>
                                <td>
                                    {{ $task->humanReadableSchedule() }}
                                    @if ($task->timezone)<div class="faint text-xs">{{ $task->timezone }}</div>@endif
                                </td>
                                <td>
                                    @include('admin.schedules._status', ['run' => $task->latestRun])
                                    @if ($task->latestRun)
                                        <div class="faint text-xs">{{ $task->latestRun->started_at->diffForHumans() }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if (! $task->enabled)
                                        <x-status-badge tone="mute">{{ __('Disabled') }}</x-status-badge>
                                    @elseif ($next = $task->nextRunDate())
                                        <span title="{{ $next->toDayDateTimeString() }}">{{ $next->diffForHumans() }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('admin.schedules.edit', $task) }}" class="btn btn-sm">{{ __('Edit') }}</a>
                                        <form method="post" action="{{ route($task->enabled ? 'admin.schedules.disable' : 'admin.schedules.enable', $task) }}">
                                            @csrf
                                            @method('patch')
                                            <button type="submit" class="btn btn-sm">{{ $task->enabled ? __('Disable') : __('Enable') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="field-hint">{{ __('Next run') }}: {{ __('times are worked out from each task\'s schedule; they only happen if the server\'s cron entry is running.') }}</p>
        @endif
    </x-card>
</x-layouts.app>
