<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team Sponsorship's cached BioSteel Teams data, one row per (email, team
 * name) that has ever had a sponsorship order, in the old app's final shape
 * (`registered_players` replaced by `emails_count`, 2026-09-03). Refreshed
 * daily by `team-sponsorship:refresh-activity`: resolving a team's BioSteel
 * team ID means paging the whole org-wide roster, too slow to do per row on
 * the Stats page. Holds coaches' emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_sponsorship_activity', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('team_name');
            $table->string('team_id')->nullable();
            $table->string('user_id')->nullable();
            $table->unsignedInteger('emails_count')->nullable();
            $table->unsignedInteger('events_count')->nullable();
            $table->unsignedInteger('games_count')->nullable();
            $table->unsignedInteger('practices_count')->nullable();
            $table->dateTime('refreshed_at')->nullable();
            $table->string('last_error')->nullable();

            $table->unique(['email', 'team_name'], 'team_sponsorship_activity_email_team_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_sponsorship_activity');
    }
};
