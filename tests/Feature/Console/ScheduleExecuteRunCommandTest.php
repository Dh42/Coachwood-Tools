<?php

namespace Tests\Feature\Console;

use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Notifications\ScheduledTaskFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduleExecuteRunCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
    }

    private function pendingRun(string $command): ScheduledTaskRun
    {
        // Changed on purpose: scheduled_tasks.command is unique now (found
        // issue 18), so the heartbeat row the migration registers is removed
        // before this test adds its own.
        ScheduledTask::where('command', $command)->delete();

        $task = ScheduledTask::forceCreate([
            'name' => 'Test Task', 'description' => 'For testing.', 'command' => $command,
            'cron_expression' => '0 * * * *', 'enabled' => true,
        ]);

        return $task->runs()->create(['started_at' => now(), 'triggered_by' => 'manual']);
    }

    /**
     * Calls Artisan::call() directly rather than the $this->artisan() test
     * helper — confirmed via a throwaway debug test that $this->artisan()'s
     * PendingCommand wrapper breaks a *nested* Artisan::call()'s ability to
     * capture output into a fresh BufferedOutput (works fine standalone,
     * e.g. via tinker, and works fine in production — this is specific to
     * how the test harness wraps the outer call, not a real bug in the
     * command itself).
     */
    public function test_closes_out_the_run_on_success(): void
    {
        $run = $this->pendingRun('scheduler:heartbeat');

        $exitCode = Artisan::call('schedule:execute-run', ['run_id' => $run->id]);

        $this->assertSame(0, $exitCode);
        $run->refresh();
        $this->assertSame(0, $run->exit_code);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('Scheduler heartbeat', $run->output);
    }

    public function test_notifies_every_admin_on_a_non_zero_exit_code(): void
    {
        Notification::fake();
        Artisan::command('test:always-fail', fn () => 1);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $run = $this->pendingRun('test:always-fail');

        $exitCode = Artisan::call('schedule:execute-run', ['run_id' => $run->id]);

        $this->assertSame(0, $exitCode); // schedule:execute-run itself succeeds even though the target command it ran did not
        $run->refresh();
        $this->assertSame(1, $run->exit_code);
        $this->assertNotNull($run->finished_at);
        Notification::assertSentTo($admin, ScheduledTaskFailedNotification::class);
    }

    public function test_missing_run_record_fails_cleanly(): void
    {
        $exitCode = Artisan::call('schedule:execute-run', ['run_id' => 999999]);

        $this->assertNotSame(0, $exitCode);
    }
}
