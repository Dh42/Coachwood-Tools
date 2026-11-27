<?php

namespace Tests\Feature\Legacy;

use App\Models\TeamAppAdSnapshot;
use App\Models\TeamAppCategorySnapshot;
use App\Models\TeamAppSnapshot;
use App\Models\TeamAppSocialPostSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `legacy:import-team-app-stats` and `compare:team-app-stats` against a
 * made-up old database (the in-memory `legacy` connection). Every value
 * here is invented.
 */
class ImportTeamAppStatsTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_TABLES = [
        'team_app_category_snapshots', 'team_app_snapshots', 'team_app_ad_snapshots', 'team_app_social_post_snapshots',
        'team_app_social_follow_snapshots', 'team_app_snapshot_teams_backup', 'team_app_category_snapshots_backup',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (self::OLD_TABLES as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('team_app_snapshots', function (Blueprint $t) {
            $t->id();
            $t->dateTime('captured_at')->unique();
            $t->integer('users')->nullable();
            $t->integer('teams')->nullable();
        });
        $schema->create('team_app_category_snapshots', function (Blueprint $t) {
            $t->unsignedBigInteger('snapshot_id');
            $t->string('category', 64);
            $t->integer('teams')->nullable();
        });
        $schema->create('team_app_ad_snapshots', function (Blueprint $t) {
            $t->date('date')->primary();
            foreach (['impressions', 'clicks', 'ad_requests', 'viewable_impressions', 'measurable_impressions'] as $c) {
                $t->unsignedInteger($c)->nullable();
            }
        });
        $schema->create('team_app_social_post_snapshots', function (Blueprint $t) {
            $t->id();
            $t->date('captured_at');
            $t->string('period', 16);
            $t->unsignedInteger('rank');
            $t->string('post_id');
            foreach (['post_type', 'visibility', 'visibility_team_id', 'text', 'author_user_id', 'author_name', 'author_username', 'author_email', 'team_id', 'team_name'] as $c) {
                $t->string($c)->nullable();
            }
            $t->dateTime('post_created_at')->nullable();
            $t->dateTime('last_interaction_at')->nullable();
            $t->boolean('published_in_period')->nullable();
            foreach (['likes_in_period', 'dislikes_in_period', 'shares_in_period', 'other_reactions_in_period', 'reactions_in_period', 'comments_in_period', 'interactions_in_period', 'viewers_in_period', 'total_reactions', 'total_comments', 'total_view_count'] as $c) {
                $t->integer($c)->nullable();
            }
        });
        $schema->create('team_app_social_follow_snapshots', function (Blueprint $t) {
            $t->id();
            $t->date('captured_at');
            $t->string('period', 16);
            $t->unsignedInteger('rank');
            $t->string('account_type', 16);
            foreach (['account_id', 'display_name', 'username', 'sport_type', 'email', 'phone'] as $c) {
                $t->string($c)->nullable();
            }
            $t->integer('new_followers')->nullable();
            $t->integer('total_followers')->nullable();
        });
        $schema->create('team_app_snapshot_teams_backup', function (Blueprint $t) {
            $t->unsignedBigInteger('snapshot_id')->primary();
            $t->integer('teams')->nullable();
        });
        $schema->create('team_app_category_snapshots_backup', function (Blueprint $t) {
            $t->unsignedBigInteger('snapshot_id');
            $t->string('category', 64);
            $t->integer('teams')->nullable();
        });

        $old = DB::connection('legacy');
        // Old IDs far from the new ones, to prove the sport rows are re-mapped.
        $old->table('team_app_snapshots')->insert([
            ['id' => 501, 'captured_at' => '2026-09-01 10:00:00', 'users' => 1000, 'teams' => 100],
            ['id' => 502, 'captured_at' => '2026-09-02 10:00:00', 'users' => 1010, 'teams' => 102],
        ]);
        $old->table('team_app_category_snapshots')->insert([
            ['snapshot_id' => 501, 'category' => 'HOCKEY', 'teams' => 60],
            ['snapshot_id' => 501, 'category' => 'SOCCER', 'teams' => 40],
            ['snapshot_id' => 502, 'category' => 'HOCKEY', 'teams' => 61],
            ['snapshot_id' => 502, 'category' => 'SOCCER', 'teams' => 41],
        ]);
        $old->table('team_app_ad_snapshots')->insert([
            ['date' => '2026-09-01', 'impressions' => 500, 'clicks' => 5, 'ad_requests' => 600, 'viewable_impressions' => 300, 'measurable_impressions' => 400],
        ]);
        $old->table('team_app_social_post_snapshots')->insert([
            ['captured_at' => '2026-09-02', 'period' => 'WEEK', 'rank' => 1, 'post_id' => 'post-a', 'author_email' => 'author@example.test', 'interactions_in_period' => 9],
        ]);
        $old->table('team_app_social_follow_snapshots')->insert([
            ['captured_at' => '2026-09-02', 'period' => 'WEEK', 'rank' => 1, 'account_type' => 'TEAM', 'account_id' => 'team-a', 'phone' => '555-0100', 'new_followers' => 3],
        ]);
        $old->table('team_app_snapshot_teams_backup')->insert([['snapshot_id' => 501, 'teams' => 160]]);
        $old->table('team_app_category_snapshots_backup')->insert([['snapshot_id' => 501, 'category' => 'HOCKEY', 'teams' => 90]]);
    }

    public function test_it_copies_every_table_and_re_maps_sport_rows_to_the_new_snapshot_ids(): void
    {
        $this->artisan('legacy:import-team-app-stats')->assertSuccessful();

        $first = TeamAppSnapshot::where('captured_at', '2026-09-01 10:00:00')->sole();
        $this->assertNotSame(501, $first->id);
        $this->assertSame(1000, $first->users);
        $this->assertSame(['HOCKEY' => 60, 'SOCCER' => 40], $first->categories()->orderBy('category')->pluck('teams', 'category')->all());
        $this->assertSame(4, TeamAppCategorySnapshot::count());

        $this->assertSame(500, TeamAppAdSnapshot::sole()->impressions);
        $this->assertSame('post-a', TeamAppSocialPostSnapshot::sole()->post_id);
        $this->assertSame('555-0100', DB::table('team_app_social_follow_snapshots')->value('phone'));
        $this->assertSame(160, (int) DB::table('team_app_snapshot_teams_backup')->where('snapshot_id', $first->id)->value('teams'));
        $this->assertSame(90, (int) DB::table('team_app_category_snapshots_backup')->where('snapshot_id', $first->id)->value('teams'));
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->artisan('legacy:import-team-app-stats')->assertSuccessful();

        $this->artisan('legacy:import-team-app-stats')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['team_app_snapshots', 2, 0, 2, 0],
                ['team_app_category_snapshots', 4, 0, 0, 0],
                ['team_app_ad_snapshots', 1, 0, 1, 0],
                ['team_app_social_post_snapshots', 1, 0, 1, 0],
                ['team_app_social_follow_snapshots', 1, 0, 1, 0],
                ['team_app_snapshot_teams_backup', 1, 0, 1, 0],
                ['team_app_category_snapshots_backup', 1, 0, 1, 0],
            ])
            ->assertSuccessful();

        $this->assertSame(2, TeamAppSnapshot::count());
        $this->assertSame(4, TeamAppCategorySnapshot::count());
    }

    public function test_the_old_apps_reading_wins_for_an_hour_both_apps_captured(): void
    {
        // This app's own hourly snapshot for the same hour, with a sport the old one lacks.
        $mine = TeamAppSnapshot::create(['captured_at' => '2026-09-02 10:00:00', 'users' => 999, 'teams' => 99]);
        TeamAppCategorySnapshot::create(['snapshot_id' => $mine->id, 'category' => 'RUGBY', 'teams' => 5]);

        $this->artisan('legacy:import-team-app-stats')->assertSuccessful();

        $this->assertSame(1010, $mine->fresh()->users);
        $this->assertSame(['HOCKEY', 'SOCCER'], $mine->categories()->orderBy('category')->pluck('category')->all());
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('legacy:import-team-app-stats', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was saved.')
            ->assertSuccessful();

        $this->assertSame(0, TeamAppSnapshot::count());
    }

    public function test_the_output_holds_no_names_emails_or_phones(): void
    {
        $this->artisan('legacy:import-team-app-stats')
            ->doesntExpectOutputToContain('author@example.test')
            ->doesntExpectOutputToContain('555-0100')
            ->assertSuccessful();
    }

    public function test_after_the_import_both_apps_report_the_same_numbers(): void
    {
        $this->artisan('legacy:import-team-app-stats')->assertSuccessful();

        $this->artisan('compare:team-app-stats', ['--from' => '2026-09-01', '--to' => '2026-09-02'])
            ->expectsOutputToContain('Everything matches.')
            ->assertSuccessful();
    }

    public function test_a_difference_is_reported_without_printing_values(): void
    {
        $this->artisan('legacy:import-team-app-stats')->assertSuccessful();
        TeamAppSnapshot::where('captured_at', '2026-09-02 10:00:00')->update(['users' => 4321]);

        $this->artisan('compare:team-app-stats', ['--from' => '2026-09-01', '--to' => '2026-09-02'])
            ->expectsOutputToContain('2 section(s) differ.')
            ->doesntExpectOutputToContain('4321')
            ->doesntExpectOutputToContain('1010')
            ->assertFailed();
    }

    public function test_the_comparison_defaults_to_the_30_days_ending_yesterday(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');

        $this->artisan('compare:team-app-stats')->expectsOutputToContain('Period: 2026-09-01 to 2026-09-30 (UTC)');

        Carbon::setTestNow();
    }
}
