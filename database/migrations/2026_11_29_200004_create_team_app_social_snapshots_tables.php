<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical daily leaderboard snapshots for BioSteel Teams' Social
 * Insights (Admin) API (launched 2026-09) — the live leaderboard itself is
 * fetched fresh on every dashboard load (see SocialInsightsClient), same as
 * this tool's GA4 section; these tables exist purely so a past day's
 * leaderboard can still be viewed later, per the user's explicit request
 * ("show the live, then store it after the end of the day"). Only the WEEK
 * period is captured daily (matching the live view's own default) — the
 * API is a rolling-period report, not a per-day total, so storing all
 * three periods daily would be mostly redundant history for no real use
 * yet; TWO_WEEKS/MONTH can be added the same way later if wanted.
 *
 * UUID-shaped ids (accountId, authorUserId, teamId) are plain strings, not
 * a uuid() column — matches this app's own established convention for
 * BioSteel's ids (see team_sponsorship_activity.team_id/user_id) rather
 * than assuming that shape is guaranteed to stay a canonical UUID forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_app_social_post_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('captured_at');
            $table->string('period', 16);
            $table->unsignedInteger('rank');
            $table->string('post_id');
            $table->string('post_type', 32)->nullable();
            $table->string('visibility', 32)->nullable();
            $table->string('visibility_team_id')->nullable();
            $table->dateTime('post_created_at')->nullable();
            $table->text('text')->nullable();
            $table->string('author_user_id')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_username')->nullable();
            $table->string('author_email')->nullable();
            $table->string('team_id')->nullable();
            $table->string('team_name')->nullable();
            $table->boolean('published_in_period')->nullable();
            $table->integer('likes_in_period')->nullable();
            $table->integer('dislikes_in_period')->nullable();
            $table->integer('shares_in_period')->nullable();
            $table->integer('other_reactions_in_period')->nullable();
            $table->integer('reactions_in_period')->nullable();
            $table->integer('comments_in_period')->nullable();
            $table->integer('interactions_in_period')->nullable();
            $table->dateTime('last_interaction_at')->nullable();
            $table->unsignedBigInteger('viewers_in_period')->nullable();
            $table->integer('total_reactions')->nullable();
            $table->integer('total_comments')->nullable();
            $table->unsignedBigInteger('total_view_count')->nullable();

            $table->unique(['captured_at', 'period', 'rank']);
        });

        Schema::create('team_app_social_follow_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('captured_at');
            $table->string('period', 16);
            $table->unsignedInteger('rank');
            $table->string('account_type', 16);
            $table->string('account_id')->nullable();
            $table->string('display_name')->nullable();
            $table->string('username')->nullable();
            $table->string('sport_type')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->integer('new_followers')->nullable();
            $table->integer('total_followers')->nullable();

            $table->unique(['captured_at', 'period', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_app_social_follow_snapshots');
        Schema::dropIfExists('team_app_social_post_snapshots');
    }
};
