<?php

namespace Tests\Feature\Console;

use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchedulerHeartbeatRunHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_a_registered_event_records_a_closed_out_run(): void
    {
        Role::firstOrCreate(['name' => 'admin']);

        $task = ScheduledTask::where('command', 'scheduler:heartbeat')->firstOrFail();

        $schedule = new Schedule;
        ScheduledTask::registerAllOnto($schedule);

        $event = $schedule->events()[0];
        $event->run($this->app);

        $run = ScheduledTaskRun::where('scheduled_task_id', $task->id)->latest('started_at')->firstOrFail();

        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, $run->exit_code);
        $this->assertStringContainsString('Scheduler heartbeat', $run->output);
        $this->assertSame('success', $run->status());
    }
}
