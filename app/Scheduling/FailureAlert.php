<?php

namespace App\Scheduling;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Notifications\ScheduledTaskFailedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emails every active admin when a scheduled run fails, from both the cron
 * path (ScheduledTask::registerAllOnto) and Run Now (schedule:execute-run).
 *
 * Fixes to the old app (docs/found-issues.md):
 * - 18: disabled admins are skipped
 * - new: if the email itself can't be sent (Gmail not connected, Google
 *   down), that is logged and the run record stands, instead of the
 *   exception escaping the scheduler
 */
final class FailureAlert
{
    public static function send(ScheduledTaskRun $run): void
    {
        $admins = User::role('admin')->whereNull('disabled_at')->get();

        if ($admins->isEmpty()) {
            return;
        }

        try {
            Notification::send($admins, new ScheduledTaskFailedNotification($run));
        } catch (Throwable $e) {
            Log::error('Scheduled task failure email could not be sent', ['run_id' => $run->id, 'message' => $e->getMessage()]);
        }
    }
}
