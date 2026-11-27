<x-layouts.app :title="'Edit '.$task->name" breadcrumb="Admin · Scheduler">
    <x-slot:subtitle>Command: <code>{{ $task->command }}</code> (set by the tool, not editable)</x-slot:subtitle>

    <x-card>
        <form method="post" action="{{ route('admin.schedules.update', $task) }}" class="form-stack">
            @csrf
            @method('put')

            <x-form-field name="name" :label="__('Name')" :value="old('name', $task->name)" required maxlength="255" />

            <div class="field">
                <label for="description">{{ __('Description') }}</label>
                <textarea id="description" name="description" class="input textarea" rows="2" maxlength="1000">{{ old('description', $task->description) }}</textarea>
            </div>

            <x-form-field name="cron_expression" :label="__('Schedule (cron)')" :value="old('cron_expression', $task->cron_expression)" required
                          :hint="__('minute hour day-of-month month day-of-week. For example: */5 * * * * is every 5 minutes; 30 2 * * 1 is Mondays at 02:30.')" />

            <x-form-field name="timezone" :label="__('Timezone')" :value="old('timezone', $task->timezone)" list="timezones" placeholder="UTC"
                          :hint="__('Leave empty for UTC. For example America/Toronto.')" />
            <datalist id="timezones">
                @foreach (['America/Toronto', 'America/Vancouver', 'America/New_York', 'UTC'] as $zone)
                    <option value="{{ $zone }}"></option>
                @endforeach
            </datalist>

            <label class="check">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $task->enabled))>
                <span>{{ __('Enabled') }}</span>
            </label>

            <div class="form-actions">
                <a href="{{ route('admin.schedules.index') }}" class="btn">{{ __('Cancel') }}</a>
                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            </div>
        </form>
    </x-card>
</x-layouts.app>
