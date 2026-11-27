<?php

use App\Models\ScheduledTask;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler is database-driven (Admin > Scheduler), as in the old app:
// the server's one cron entry runs `schedule:run` every minute, and every
// enabled scheduled_tasks row is registered here.
//
// This file is `require`d on every Artisan invocation, including `migrate`
// itself on a fresh install — before the scheduled_tasks table exists.
if (Schema::hasTable('scheduled_tasks')) {
    ScheduledTask::registerAllOnto(Schedule::getFacadeRoot());
}
