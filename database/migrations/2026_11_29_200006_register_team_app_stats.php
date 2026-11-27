<?php

use App\Scheduling\TaskRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * BioSteel App Stats: the tool and its three scheduled tasks, as in the old
 * app (name and description as the old app's admins last set them).
 * `legacy:import-platform` then copies the old order, active flag and task
 * schedules.
 */
return new class extends Migration
{
    private const TASKS = ['team-app-stats:snapshot', 'team-app-stats:collect-ads', 'team-app-stats:snapshot-social'];

    public function up(): void
    {
        ToolRegistry::register([
            'slug' => 'team-app-stats',
            'name' => 'BioSteel Teams App Stats',
            'description' => 'Stats dashboard for the BioSteel Teams App. Updates hourly, has exports for common formats for the stats data.',
            'group' => 'BioSteel Teams',
            'icon' => 'phone',
            'url' => '/tools/team-app-stats',
        ]);

        TaskRegistry::register([
            'command' => 'team-app-stats:snapshot',
            'name' => 'BioSteel App Stats Snapshot',
            'description' => 'Pulls current user/team totals and the sport breakdown from the BioSteel Teams API and writes one hourly snapshot.',
            'cron_expression' => '0 * * * *',
        ]);
        TaskRegistry::register([
            'command' => 'team-app-stats:collect-ads',
            'name' => 'BioSteel App Stats Ad Collection',
            'description' => "Pulls yesterday's Google Ad Manager metrics and upserts that day's row. Runs 3x/day (6am/12pm/6pm) since Ad Manager revises a day's numbers for hours after it ends — each re-run just refreshes the row with a more accurate value.",
            'cron_expression' => '0 6,12,18 * * *',
        ]);
        TaskRegistry::register([
            'command' => 'team-app-stats:snapshot-social',
            'name' => 'BioSteel App Stats Social Snapshot',
            'description' => "Captures today's Social Insights top-posts/top-followed leaderboards (WEEK period) for history — runs near end of day so the captured date reflects a full day.",
            'cron_expression' => '55 23 * * *',
            'timezone' => 'America/Toronto',
        ]);
    }

    public function down(): void
    {
        foreach (self::TASKS as $command) {
            TaskRegistry::unregister($command);
        }

        ToolRegistry::unregister('team-app-stats');
    }
};
