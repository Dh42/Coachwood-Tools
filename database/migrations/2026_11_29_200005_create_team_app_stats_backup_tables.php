<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two backup tables the old app's one-off team-totals correction
 * (2026-09-30) created, holding the values it overwrote. The correction
 * itself is not ported: the data imported from the old app is already
 * corrected, and its source CSV holds real figures that must not be
 * committed. The backups are copied by `legacy:import-team-app-stats` so
 * the originals are kept. Keyed by snapshot_id, re-mapped to this app's IDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_app_snapshot_teams_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('snapshot_id')->primary();
            $table->integer('teams')->nullable();
        });

        Schema::create('team_app_category_snapshots_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('snapshot_id');
            $table->string('category', 64);
            $table->integer('teams')->nullable();

            $table->primary(['snapshot_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_app_category_snapshots_backup');
        Schema::dropIfExists('team_app_snapshot_teams_backup');
    }
};
