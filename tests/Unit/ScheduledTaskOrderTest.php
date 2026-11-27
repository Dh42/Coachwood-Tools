<?php

namespace Tests\Unit;

use App\Models\ScheduledTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Found issue 23: Laravel runs the tasks due in one minute one after
 * another, so a 10-20 minute task (the Walmart audit at 06:00) held up
 * everything else due at 06:00. Tasks are now registered shortest first,
 * by their last finished run; tasks that never ran count as short.
 */
class ScheduledTaskOrderTest extends TestCase
{
    use RefreshDatabase;

    private function task(string $command, ?int $lastRunSeconds): ScheduledTask
    {
        $task = ScheduledTask::forceCreate(['name' => $command, 'command' => $command, 'cron_expression' => '0 6 * * *', 'enabled' => true]);
        if ($lastRunSeconds !== null) {
            $task->runs()->create(['started_at' => now()->subDay(), 'finished_at' => now()->subDay()->addSeconds($lastRunSeconds), 'exit_code' => 0, 'triggered_by' => 'schedule']);
        }

        return $task;
    }

    public function test_tasks_due_together_run_shortest_first(): void
    {
        ScheduledTask::query()->delete();
        $this->task('test:slow-audit', 1200);
        $this->task('test:quick-sync', 4);
        $this->task('test:never-ran', null);

        $schedule = new Schedule;
        ScheduledTask::registerAllOnto($schedule);

        $commands = array_map(fn ($event) => preg_replace('/.*artisan\'? /', '', $event->command), $schedule->events());
        $this->assertSame(['test:never-ran', 'test:quick-sync', 'test:slow-audit'], $commands);
    }
}
