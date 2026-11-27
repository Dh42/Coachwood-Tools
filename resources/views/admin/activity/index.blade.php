{{-- Admin > Activity, ported from the old app: newest first, 25 a page, filtered by category. --}}
<x-layouts.app title="Activity" breadcrumb="Admin">
    <x-slot:subtitle>Sign-ins, admin changes and tool events, kept for 365 days.</x-slot:subtitle>

    <x-card>
        <form method="get" class="filter-bar">
            <label class="field">
                <span>{{ __('Category') }}</span>
                <select name="log_name" class="select" onchange="this.form.submit()">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($logNames as $logName)
                        <option value="{{ $logName }}" @selected($selectedLogName === $logName)>{{ $logName }}</option>
                    @endforeach
                </select>
            </label>
            <noscript><button type="submit" class="btn">{{ __('Filter') }}</button></noscript>
        </form>

        @if ($activities->isEmpty())
            <x-empty-state title="No activity recorded yet" icon="clock" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('When') }} <span class="faint">(UTC)</span></th>
                            <th scope="col">{{ __('Category') }}</th>
                            <th scope="col">{{ __('Who') }}</th>
                            <th scope="col">{{ __('Event') }}</th>
                            <th scope="col">{{ __('Subject') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activities as $activity)
                            <tr>
                                <td>{{ $activity->created_at->format('Y-m-d H:i') }}</td>
                                <td><x-status-badge tone="mute" :icon="false">{{ $activity->log_name }}</x-status-badge></td>
                                <td>{{ $activity->causer?->name ?? __('System') }}</td>
                                <td>{{ $activity->description }}</td>
                                <td class="muted">
                                    @if ($activity->subject)
                                        {{ class_basename($activity->subject_type) }}: {{ $activity->subject->name ?? '#'.$activity->subject_id }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$activities" />
        @endif
    </x-card>
</x-layouts.app>
