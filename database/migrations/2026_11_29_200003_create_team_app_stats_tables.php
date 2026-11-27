<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BioSteel App Stats — ported from the standalone `biosteel_teams_stats`
 * tool's MySQL schema (snapshots/category_snapshots/ad_snapshots), prefixed
 * team_app_* to avoid colliding with this app's own unrelated `teams`/
 * `team_user` tables (Admin > Teams distribution lists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_app_snapshots', function (Blueprint $table) {
            $table->id();
            $table->dateTime('captured_at')->unique();
            $table->integer('users')->nullable();
            $table->integer('teams')->nullable();
        });

        Schema::create('team_app_category_snapshots', function (Blueprint $table) {
            $table->foreignId('snapshot_id')->constrained('team_app_snapshots')->cascadeOnDelete();
            $table->string('category', 64);
            $table->integer('teams')->nullable();

            $table->primary(['snapshot_id', 'category']);
        });

        Schema::create('team_app_ad_snapshots', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('impressions')->nullable();
            $table->unsignedInteger('clicks')->nullable();
            $table->unsignedInteger('ad_requests')->nullable();
            $table->unsignedInteger('viewable_impressions')->nullable();
            $table->unsignedInteger('measurable_impressions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_app_ad_snapshots');
        Schema::dropIfExists('team_app_category_snapshots');
        Schema::dropIfExists('team_app_snapshots');
    }
};
