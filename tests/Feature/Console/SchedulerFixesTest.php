<?php

namespace Tests\Feature\Console;

use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Notifications\ScheduledTaskFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/** Fixes to the old scheduler, each failing on the old code first (docs/found-issues.md). */
class SchedulerFixesTest extends TestCase
{
    use RefreshDatabase;

    private function pendingRun(string $command): ScheduledTaskRun
    {
        $task = ScheduledTask::forceCreate([
            'name' => 'Test Task', 'command' => $command, 'cron_expression' => '0 * * * *', 'enabled' => true,
        ]);

        return $task->runs()->create(['started_at' => now(), 'triggered_by' => 'manual']);
    }

    /** Issue 17. */
    public function test_a_run_now_whose_command_throws_is_closed_as_failed_and_admins_are_told(): void
    {
        Notification::fake();
        Artisan::command('test:explodes', fn () => throw new RuntimeException('Shopify said no'));
        $admin = User::factory()->create()->assignRole('admin');
        $run = $this->pendingRun('test:explodes');

        Artisan::call('schedule:execute-run', ['run_id' => $run->id]);

        $run->refresh();
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->exit_code);
        $this->assertStringContainsString('Shopify said no', $run->output);
        $this->assertSame('failed', $run->status());
        Notification::assertSentTo($admin, ScheduledTaskFailedNotification::class);
    }

    /** Issue 18. */
    public function test_registering_the_same_command_twice_keeps_one_row(): void
    {
        $row = ['name' => 'Twice', 'command' => 'test:twice', 'cron_expression' => '0 * * * *', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()];

        DB::table('scheduled_tasks')->insertOrIgnore([$row]);
        DB::table('scheduled_tasks')->insertOrIgnore([$row]);

        $this->assertSame(1, ScheduledTask::where('command', 'test:twice')->count());
    }

    /** Issue 18. */
    public function test_failure_emails_skip_disabled_admins(): void
    {
        Notification::fake();
        Artisan::command('test:always-fail', fn () => 1);
        $active = User::factory()->create()->assignRole('admin');
        $disabled = User::factory()->create(['disabled_at' => now()])->assignRole('admin');

        Artisan::call('schedule:execute-run', ['run_id' => $this->pendingRun('test:always-fail')->id]);

        Notification::assertSentTo($active, ScheduledTaskFailedNotification::class);
        Notification::assertNotSentTo($disabled, ScheduledTaskFailedNotification::class);
    }

    /** Issue 30. */
    public function test_the_activity_log_clean_up_is_scheduled_daily(): void
    {
        $task = ScheduledTask::where('command', 'activitylog:clean --force')->sole();

        $this->assertTrue($task->enabled);
        $this->assertSame('15 3 * * *', $task->cron_expression);
    }

    /** Issue 30: the clean-up keeps 365 days. */
    public function test_the_clean_up_removes_only_entries_older_than_365_days(): void
    {
        activity('auth')->log('Recent');
        DB::table('activity_log')->insert(['log_name' => 'auth', 'description' => 'Ancient', 'created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400)]);

        Artisan::call('activitylog:clean', ['--force' => true]);

        $this->assertSame(['Recent'], DB::table('activity_log')->pluck('description')->all());
    }

    /** New: a failure email that can't be sent (Gmail not connected) mustn't undo the run record. */
    public function test_a_failure_email_that_cannot_be_sent_still_leaves_the_run_closed(): void
    {
        config(['mail.default' => 'gmail_api']); // nothing connected: sending throws
        Artisan::command('test:always-fail', fn () => 1);
        User::factory()->create()->assignRole('admin');
        $run = $this->pendingRun('test:always-fail');

        $this->assertSame(0, Artisan::call('schedule:execute-run', ['run_id' => $run->id]));

        $this->assertSame('failed', $run->fresh()->status());
    }
}
