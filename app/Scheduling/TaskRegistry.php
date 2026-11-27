<?php

namespace App\Scheduling;

use Illuminate\Support\Facades\DB;

/**
 * The task self-registration convention, like App\Tools\ToolRegistry.
 * A tool's migration registers its scheduled commands, so the deploy that
 * adds the command also schedules it:
 *
 *     TaskRegistry::register([
 *         'command' => 'price-monitor:scan',
 *         'name' => 'Price Monitor Scan',
 *         'description' => 'Scrapes Amazon.ca prices for every tracked ASIN.',
 *         'cron_expression' => '0 *\/2 * * *',
 *         'timezone' => null,          // null means UTC (config app.timezone)
 *         'enabled' => true,
 *     ]);
 *
 * Insert-only (scheduled_tasks.command is unique): running it again never
 * overwrites what an admin changed in Admin > Scheduler. The old app wrote
 * raw inserts in each migration; this keeps them the same shape.
 */
final class TaskRegistry
{
    /**
     * @param  array{command: string, name: string, description?: ?string, cron_expression: string, timezone?: ?string, enabled?: bool}  $task
     */
    public static function register(array $task): void
    {
        $now = now();

        DB::table('scheduled_tasks')->insertOrIgnore([
            'command' => $task['command'],
            'name' => $task['name'],
            'description' => $task['description'] ?? null,
            'cron_expression' => $task['cron_expression'],
            'timezone' => $task['timezone'] ?? null,
            'enabled' => $task['enabled'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Removes the task and, through the foreign key, its run history. */
    public static function unregister(string $command): void
    {
        DB::table('scheduled_tasks')->where('command', $command)->delete();
    }
}
