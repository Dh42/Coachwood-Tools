<?php

namespace Tests\Unit;

use App\Models\ScheduledTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledTaskRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_tasks_are_registered_onto_the_schedule(): void
    {
        ScheduledTask::query()->delete();
        ScheduledTask::forceCreate([
            'name' => 'Test Task',
            'command' => 'scheduler:heartbeat',
            'cron_expression' => '30 4 * * *',
            'enabled' => true,
        ]);

        $schedule = new Schedule;
        ScheduledTask::registerAllOnto($schedule);

        $events = $schedule->events();
        $this->assertCount(1, $events);
        $this->assertSame('30 4 * * *', $events[0]->expression);
        $this->assertStringContainsString('scheduler:heartbeat', $events[0]->command);
    }

    public function test_disabled_tasks_are_not_registered(): void
    {
        ScheduledTask::query()->delete();
        ScheduledTask::forceCreate([
            'name' => 'Disabled Task',
            'command' => 'scheduler:heartbeat',
            'cron_expression' => '0 * * * *',
            'enabled' => false,
        ]);

        $schedule = new Schedule;
        ScheduledTask::registerAllOnto($schedule);

        $this->assertCount(0, $schedule->events());
    }
}
