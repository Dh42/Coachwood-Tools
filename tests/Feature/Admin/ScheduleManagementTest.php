<?php

namespace Tests\Feature\Admin;

use App\Models\ScheduledTask;
use App\Models\User;
use App\Services\Scheduler\BackgroundProcessLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    protected function task(): ScheduledTask
    {
        // Changed on purpose: scheduled_tasks.command is unique now (found
        // issue 18), so the heartbeat row the migration registers is removed
        // before this test adds its own.
        ScheduledTask::where('command', 'scheduler:heartbeat')->delete();

        return ScheduledTask::forceCreate([
            'name' => 'Test Task',
            'description' => 'A task for testing.',
            'command' => 'scheduler:heartbeat',
            'cron_expression' => '0 * * * *',
            'enabled' => true,
        ]);
    }

    public function test_non_admin_is_forbidden_from_every_schedule_route(): void
    {
        $task = $this->task();
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('admin.schedules.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.schedules.edit', $task))->assertForbidden();
        $this->actingAs($user)->get(route('admin.schedules.show', $task))->assertForbidden();
    }

    public function test_admin_can_view_index_edit_and_show(): void
    {
        $task = $this->task();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.schedules.index'))->assertOk()->assertSee('Test Task');
        $this->actingAs($admin)->get(route('admin.schedules.edit', $task))->assertOk()->assertSee('scheduler:heartbeat');
        $this->actingAs($admin)->get(route('admin.schedules.show', $task))->assertOk()->assertSee('Test Task');
    }

    public function test_admin_can_update_the_schedule_and_description_but_not_the_command(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin())->put(route('admin.schedules.update', $task), [
            'name' => 'Renamed Task',
            'description' => 'Updated description.',
            'cron_expression' => '30 2 * * 1',
            'timezone' => 'America/New_York',
            'enabled' => true,
            'command' => 'rm -rf /',
        ]);

        $task->refresh();
        $this->assertSame('Renamed Task', $task->name);
        $this->assertSame('30 2 * * 1', $task->cron_expression);
        $this->assertSame('America/New_York', $task->timezone);
        $this->assertSame('scheduler:heartbeat', $task->command);
    }

    public function test_updating_with_an_invalid_cron_expression_fails_validation(): void
    {
        $task = $this->task();

        $response = $this->actingAs($this->admin())->put(route('admin.schedules.update', $task), [
            'name' => $task->name,
            'cron_expression' => 'not a cron expression',
            'enabled' => true,
        ]);

        $response->assertSessionHasErrors('cron_expression');
        $this->assertSame('0 * * * *', $task->fresh()->cron_expression);
    }

    public function test_admin_can_enable_and_disable_a_task(): void
    {
        $task = $this->task();
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.schedules.disable', $task));
        $this->assertFalse($task->fresh()->enabled);

        $this->actingAs($admin)->patch(route('admin.schedules.enable', $task));
        $this->assertTrue($task->fresh()->enabled);
    }

    public function test_running_a_task_manually_starts_it_in_the_background_and_records_a_pending_run(): void
    {
        $task = $this->task();
        $admin = $this->admin();

        $launcher = Mockery::mock(BackgroundProcessLauncher::class);
        $launcher->shouldReceive('launchArtisanCommand')
            ->once()
            ->with('schedule:execute-run', Mockery::on(fn ($args) => count($args) === 1 && ctype_digit($args[0])));
        $this->app->instance(BackgroundProcessLauncher::class, $launcher);

        $response = $this->actingAs($admin)->post(route('admin.schedules.run', $task));

        $response->assertRedirect(route('admin.schedules.show', $task));

        $run = $task->runs()->latest('started_at')->firstOrFail();
        $this->assertSame('manual', $run->triggered_by);
        $this->assertSame($admin->id, $run->triggered_by_user_id);
        // Not run inline — the HTTP request must not block on the command finishing (see the
        // po:classify timeout this replaced), so right after the redirect the run is still "running."
        $this->assertNull($run->exit_code);
        $this->assertNull($run->finished_at);
    }
}
