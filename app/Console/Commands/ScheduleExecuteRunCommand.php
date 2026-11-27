<?php

namespace App\Console\Commands;

use App\Models\ScheduledTaskRun;
use App\Scheduling\FailureAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Internal command — not user-facing, not registered on the Scheduler
 * itself. Admin > Scheduler's "Run Now" spawns this as a detached
 * background OS process (ScheduleController::run()) rather than running
 * the target command inline within the HTTP request: a slow task
 * (po:classify's full-catalog classification pass, in particular — 17,000+
 * lines computed in memory before any writes happen) can outrun the web
 * server's request timeout, killing the request with a 500 before the run
 * row is even written, exactly like a real cron-triggered run never has to
 * worry about (schedule:run is a plain CLI process with no such ceiling).
 *
 * The ScheduledTaskRun row is created by the controller *before* this
 * process is spawned (started_at set, finished_at null — the same
 * "running" state ScheduledTaskRun::status() already recognizes for a
 * stuck/in-progress cron run); this command's only job is to run the
 * target command and close that row out.
 */
class ScheduleExecuteRunCommand extends Command
{
    protected $signature = 'schedule:execute-run {run_id}';

    protected $description = 'Internal: runs one scheduled task run\'s command and records the result. Spawned by Admin > Scheduler\'s "Run Now", not meant to be run directly.';

    public function handle(): int
    {
        $run = ScheduledTaskRun::with('scheduledTask')->find($this->argument('run_id'));
        if (! $run) {
            $this->error('Run record not found.');

            return self::FAILURE;
        }

        // Passed explicitly rather than relying on Artisan::output() — that
        // reads back the console Application's shared output buffer, which
        // doesn't reliably capture a nested Artisan::call() the same way
        // when this command is itself invoked programmatically (confirmed
        // via a real test failure: empty output despite the inner command
        // calling $this->info()).
        $outputBuffer = new BufferedOutput;

        // A command that throws is a failed run, not a run left "running"
        // forever with no email (found issue 17).
        try {
            $exitCode = Artisan::call($run->scheduledTask->command, [], $outputBuffer);
            $output = $outputBuffer->fetch();
        } catch (Throwable $e) {
            $exitCode = 1;
            $output = trim($outputBuffer->fetch()."\n".get_class($e).': '.$e->getMessage());
        }

        $run->update(['finished_at' => now(), 'exit_code' => $exitCode, 'output' => $output]);

        if ($exitCode !== 0) {
            FailureAlert::send($run->fresh());
        }

        return self::SUCCESS;
    }
}
