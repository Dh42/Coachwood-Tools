<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScheduledTask;
use App\Services\Scheduler\BackgroundProcessLauncher;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function index(): View
    {
        return view('admin.schedules.index', [
            'tasks' => ScheduledTask::query()->with('latestRun')->orderBy('name')->get(),
        ]);
    }

    public function edit(ScheduledTask $schedule): View
    {
        return view('admin.schedules.edit', ['task' => $schedule]);
    }

    public function update(Request $request, ScheduledTask $schedule): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'cron_expression' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! CronExpression::isValidExpression($value)) {
                    $fail('That is not a valid cron expression.');
                }
            }],
            'timezone' => ['nullable', 'string', 'timezone'],
            'enabled' => ['boolean'],
        ]);

        $validated['enabled'] = $request->boolean('enabled');

        // command is never mass-assignable (excluded from Fillable), so this
        // update can't touch it even if a form field is tampered with.
        $schedule->update($validated);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($schedule)
            ->log('Scheduled task updated');

        return redirect()->route('admin.schedules.index')->with('status', 'Schedule updated.');
    }

    public function show(ScheduledTask $schedule): View
    {
        return view('admin.schedules.show', [
            'task' => $schedule,
            'runs' => $schedule->runs()->latest('started_at')->paginate(25)->withQueryString(),
        ]);
    }

    public function enable(Request $request, ScheduledTask $schedule): RedirectResponse
    {
        $schedule->update(['enabled' => true]);

        activity('admin')->causedBy($request->user())->performedOn($schedule)->log('Scheduled task enabled');

        return redirect()->route('admin.schedules.index')->with('status', 'Schedule enabled.');
    }

    public function disable(Request $request, ScheduledTask $schedule): RedirectResponse
    {
        $schedule->update(['enabled' => false]);

        activity('admin')->causedBy($request->user())->performedOn($schedule)->log('Scheduled task disabled');

        return redirect()->route('admin.schedules.index')->with('status', 'Schedule disabled.');
    }

    /**
     * Spawns the command as a detached background OS process rather than
     * running it inline via Artisan::call() — a slow task (po:classify's
     * full-catalog pass, in particular) can take long enough to outrun the
     * web server's request timeout, which used to kill this request with a
     * 500 before the run row was even written. schedule:execute-run does
     * the actual work and closes the row out; this just starts it and
     * returns immediately. See BackgroundProcessLauncher's own docblock for
     * why that's exec()-based rather than Laravel's Process facade — the
     * latter doesn't actually survive past the end of this request. Known
     * gap carried over unchanged from the prior synchronous version:
     * nothing here prevents this from overlapping a concurrent
     * cron-triggered run of the same command — only the cron path itself
     * has withoutOverlapping() protection.
     */
    public function run(Request $request, ScheduledTask $schedule, BackgroundProcessLauncher $launcher): RedirectResponse
    {
        $run = $schedule->runs()->create([
            'started_at' => now(),
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $request->user()->id,
        ]);

        $launcher->launchArtisanCommand('schedule:execute-run', [(string) $run->id]);

        activity('admin')->causedBy($request->user())->performedOn($schedule)->log('Scheduled task run manually');

        return redirect()->route('admin.schedules.show', $schedule)->with('status', 'Task started in the background — refresh this page to see progress.');
    }
}
