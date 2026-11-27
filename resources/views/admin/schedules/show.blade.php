<x-layouts.app :title="$task->name" breadcrumb="Admin · Scheduler">
    <x-slot:subtitle><code>{{ $task->command }}</code> · {{ $task->humanReadableSchedule() }}{{ $task->timezone ? ' ('.$task->timezone.')' : ' (UTC)' }} · {{ $task->enabled ? __('Enabled') : __('Disabled') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('admin.schedules.edit', $task) }}" class="btn">{{ __('Edit') }}</a>
        <form method="post" action="{{ route('admin.schedules.run', $task) }}">
            @csrf
            <button type="submit" class="btn btn-primary"><x-icon name="play" size="sm" />{{ __('Run Now') }}</button>
        </form>
    </x-slot:actions>

    @if ($task->description)
        <p class="muted text-sm mb-4">{{ $task->description }}</p>
    @endif

    <x-card :title="__('Run history')">
        @if ($runs->isEmpty())
            <x-empty-state title="Never run" icon="clock">{{ __('Runs appear here once the scheduler or Run Now starts this task.') }}</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Started') }} <span class="faint">(UTC)</span></th>
                            <th scope="col">{{ __('Status') }}</th>
                            <th scope="col" class="num">{{ __('Duration') }}</th>
                            <th scope="col">{{ __('Started by') }}</th>
                            <th scope="col">{{ __('Output') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td>{{ $run->started_at->format('Y-m-d H:i:s') }}</td>
                                <td>
                                    @include('admin.schedules._status', ['run' => $run])
                                    @if (! is_null($run->exit_code) && $run->exit_code !== 0)<div class="faint text-xs">{{ __('Exit code :code', ['code' => $run->exit_code]) }}</div>@endif
                                </td>
                                <td class="num">{{ $run->duration()?->forHumans(['short' => true]) ?? '—' }}</td>
                                <td>{{ $run->triggered_by === 'manual' ? ($run->triggeredByUser?->name ?? __('An admin')) : __('Schedule') }}</td>
                                <td class="run-output">
                                    @if (filled($run->output))
                                        <details>
                                            <summary>{{ \Illuminate\Support\Str::limit(strtok(trim($run->output), "\n"), 60) }}</summary>
                                            <pre>{{ $run->output }}</pre>
                                        </details>
                                    @else
                                        <span class="faint">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$runs" />
        @endif
    </x-card>
</x-layouts.app>
