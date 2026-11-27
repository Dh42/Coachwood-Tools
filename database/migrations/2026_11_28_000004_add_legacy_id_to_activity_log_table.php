<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The old app's activity_log row ID, for rows copied by
 * `legacy:import-platform`. Re-runs match on it.
 *
 * The plan said to preserve the IDs. That would collide: this app logs
 * sign-ins from its first deploy, so its own rows take IDs 1, 2, ... that
 * the old rows also use. Nothing points at an activity ID, so the copied
 * rows get new IDs and keep the old one here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
