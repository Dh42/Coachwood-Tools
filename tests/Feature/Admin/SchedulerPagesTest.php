<?php

namespace Tests\Feature\Admin;

use App\Models\ScheduledTask;
use App\Models\User;
use App\Scheduling\TaskRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Admin > Scheduler and task registration beyond the ported tests. */
class SchedulerPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_the_heartbeat_and_activity_clean_up_are_registered_by_migration(): void
    {
        $this->assertEqualsCanonicalizing(['scheduler:heartbeat', 'activitylog:clean --force'], ScheduledTask::query()->pluck('command')->all());
    }

    public function test_a_registration_creates_the_task_once_and_never_overwrites_an_admins_changes(): void
    {
        TaskRegistry::register(['command' => 'test:sync', 'name' => 'Test Sync', 'cron_expression' => '0 2 * * *']);
        ScheduledTask::where('command', 'test:sync')->update(['enabled' => false, 'cron_expression' => '0 4 * * *']);

        TaskRegistry::register(['command' => 'test:sync', 'name' => 'Test Sync', 'cron_expression' => '0 2 * * *']);

        $task = ScheduledTask::where('command', 'test:sync')->sole();
        $this->assertFalse($task->enabled);
        $this->assertSame('0 4 * * *', $task->cron_expression);

        TaskRegistry::unregister('test:sync');
        $this->assertDatabaseMissing('scheduled_tasks', ['command' => 'test:sync']);
    }

    public function test_the_sidebar_links_the_scheduler_for_admins(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertSee('href="'.route('admin.schedules.index').'"', false);
    }

    public function test_the_list_shows_each_tasks_schedule_last_run_and_next_run(): void
    {
        $task = ScheduledTask::where('command', 'scheduler:heartbeat')->sole();
        $task->runs()->create(['started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(3), 'exit_code' => 1, 'output' => 'boom', 'triggered_by' => 'schedule']);

        $this->actingAs($this->admin)->get(route('admin.schedules.index'))
            ->assertSee('Scheduler Heartbeat')
            ->assertSee('Every 5 minutes')
            ->assertSee('Failed')
            ->assertSee('Next run');
    }

    public function test_a_task_that_never_ran_says_so(): void
    {
        $this->actingAs($this->admin)->get(route('admin.schedules.index'))->assertSee('Never run');
    }

    public function test_run_history_shows_output_and_who_started_a_manual_run(): void
    {
        $task = ScheduledTask::where('command', 'scheduler:heartbeat')->sole();
        $task->runs()->create(['started_at' => now()->subMinute(), 'finished_at' => now(), 'exit_code' => 0, 'output' => 'Scheduler heartbeat at noon', 'triggered_by' => 'manual', 'triggered_by_user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('admin.schedules.show', $task))
            ->assertSee('Scheduler heartbeat at noon')
            ->assertSee('Succeeded')
            ->assertSee($this->admin->name);
    }

    public function test_disabling_a_task_is_logged(): void
    {
        $task = ScheduledTask::where('command', 'scheduler:heartbeat')->sole();

        $this->actingAs($this->admin)->patch(route('admin.schedules.disable', $task));

        $this->assertSame('Scheduled task disabled', Activity::query()->latest('id')->value('description'));
    }
}
