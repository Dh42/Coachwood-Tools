<?php

namespace App\Models;

use App\Scheduling\FailureAlert;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Fillable(['name', 'description', 'cron_expression', 'timezone', 'enabled'])]
class ScheduledTask extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledTaskRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(ScheduledTaskRun::class)->latestOfMany('started_at');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function humanReadableSchedule(): string
    {
        return match ($this->cron_expression) {
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 minutes',
            '*/15 * * * *' => 'Every 15 minutes',
            '*/30 * * * *' => 'Every 30 minutes',
            '0 * * * *' => 'Every hour',
            default => $this->cron_expression,
        };
    }

    public function nextRunDate(): ?Carbon
    {
        try {
            return Carbon::instance((new CronExpression($this->cron_expression))->getNextRunDate('now', 0, false, $this->timezone));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Registers every enabled task onto the given Schedule instance. Kept as
     * a plain static method (not inline in routes/console.php, which is a
     * bare `require`d file with no injected Schedule instance in Laravel 13)
     * so it can be unit tested against a fresh Schedule() without invoking
     * a real `artisan schedule:run`.
     */
    public static function registerAllOnto(Schedule $schedule): void
    {
        $tasks = static::enabled()->get();

        if ($tasks->isEmpty()) {
            return;
        }

        File::ensureDirectoryExists(storage_path('app/schedule-output'));

        foreach ($tasks as $task) {
            $outputPath = storage_path('app/schedule-output/'.$task->id.'-'.Str::uuid().'.log');

            $run = null;

            // Broken out of the fluent chain (rather than one long ->then()
            // call) so $event can be captured by the after() closure below —
            // that's how $event->exitCode (set by Laravel just before after()
            // callbacks run) is read; a closure defined in this static method
            // has no $this to fall back on.
            $event = $schedule->command($task->command)
                ->cron($task->cron_expression)
                ->timezone($task->timezone ?? config('app.timezone'))
                ->withoutOverlapping()
                ->sendOutputTo($outputPath);

            $event->before(function () use ($task, &$run) {
                $run = ScheduledTaskRun::create([
                    'scheduled_task_id' => $task->id,
                    'started_at' => now(),
                    'triggered_by' => 'schedule',
                ]);
            });

            $event->after(function () use ($event, $outputPath, &$run) {
                if (! $run) {
                    return;
                }

                $run->update([
                    'finished_at' => now(),
                    'exit_code' => $event->exitCode,
                    'output' => is_file($outputPath) ? file_get_contents($outputPath) : null,
                ]);

                @unlink($outputPath);
            });

            $event->onFailure(function () use (&$run) {
                if ($run) {
                    FailureAlert::send($run);
                }
            });
        }
    }
}
