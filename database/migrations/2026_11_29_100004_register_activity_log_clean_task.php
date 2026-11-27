<?php

use App\Scheduling\TaskRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * Applies the activity log's 365-day retention (config/activitylog.php).
 * The old app had the setting but never scheduled the clean-up, so nothing
 * was ever deleted (found issue 30). `--force` because the command asks for
 * confirmation in production and gives up when nobody answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        TaskRegistry::register([
            'command' => 'activitylog:clean --force',
            'name' => 'Activity Log Clean-up',
            'description' => 'Deletes activity log entries older than 365 days.',
            'cron_expression' => '15 3 * * *',
        ]);
    }

    public function down(): void
    {
        TaskRegistry::unregister('activitylog:clean --force');
    }
};
