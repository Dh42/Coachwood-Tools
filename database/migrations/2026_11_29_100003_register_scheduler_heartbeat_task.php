<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_tasks')->insertOrIgnore([[
            'name' => 'Scheduler Heartbeat',
            'description' => 'Proves the scheduler pipeline runs end to end. Safe to disable or delete once real jobs are registered.',
            'command' => 'scheduler:heartbeat',
            'cron_expression' => '*/5 * * * *',
            'timezone' => null,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    }

    public function down(): void
    {
        DB::table('scheduled_tasks')->where('command', 'scheduler:heartbeat')->delete();
    }
};
