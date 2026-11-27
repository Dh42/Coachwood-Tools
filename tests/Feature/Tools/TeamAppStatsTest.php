<?php

namespace Tests\Feature\Tools;

use App\Models\TeamAppAdSnapshot;
use App\Models\TeamAppCategorySnapshot;
use App\Models\TeamAppSnapshot;
use App\Models\TeamAppSocialFollowSnapshot;
use App\Models\TeamAppSocialPostSnapshot;
use App\Models\Tool;
use App\Models\User;
use App\Services\TeamAppStats\Ga4Client;
use App\Services\TeamAppStats\SocialInsightsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamAppStatsTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $this->tool = Tool::where('slug', 'team-app-stats')->firstOrFail();
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    private function snapshot(string $capturedAt, int $users, int $teams, array $bySport = []): TeamAppSnapshot
    {
        $snapshot = TeamAppSnapshot::create(['captured_at' => $capturedAt, 'users' => $users, 'teams' => $teams]);

        foreach ($bySport as $category => $count) {
            TeamAppCategorySnapshot::create(['snapshot_id' => $snapshot->id, 'category' => $category, 'teams' => $count]);
        }

        return $snapshot;
    }

    public function test_users_without_the_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.team-app-stats.index'))->assertForbidden();
    }

    public function test_index_shows_the_settings_gear_and_multiplier_data_raw_attributes(): void
    {
        $this->snapshot(now()->toDateTimeString(), 1200, 130, ['HOCKEY' => 80]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.index'));

        $response->assertOk();
        $response->assertSee('id="tas-settings-modal"', false);
        $response->assertSee('Software Version');
        // Users-now KPI card must carry its true underlying value for the
        // client-side multiplier to scale from — not just the formatted "1,200".
        $response->assertSee('data-raw="1200"', false);
    }

    public function test_index_has_the_city_and_province_share_lists(): void
    {
        $this->snapshot(now()->toDateTimeString(), 1200, 130, ['HOCKEY' => 80]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.index'));

        $response->assertOk();
        $response->assertSee('id="tas-ga4-city-share-rows"', false);
        $response->assertSee('id="tas-ga4-region-share-rows"', false);
        $response->assertSee('Share of users');
        $response->assertDontSee('id="tas-ga4-city-rows"', false);
    }

    public function test_ga4_active_users_lives_in_the_top_kpi_row_only(): void
    {
        $this->snapshot(now()->toDateTimeString(), 1200, 130, ['HOCKEY' => 80]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.index'));

        $response->assertOk();
        $response->assertSeeInOrder(['id="tas-kpi-cards"', 'id="tas-kpi-ga4-users"', 'id="tas-ga4-cards"'], false);
        $response->assertDontSee('id="tas-ga4-users"', false);
    }

    public function test_index_shows_the_pdf_and_csv_export_buttons_and_their_supporting_ids(): void
    {
        $this->snapshot(now()->toDateTimeString(), 1200, 130, ['HOCKEY' => 80]);
        // Ad performance renders its KPI cards (and their ids) only when
        // there's at least one ad row — otherwise it's just an empty-state message.
        TeamAppAdSnapshot::create([
            'date' => now()->toDateString(),
            'impressions' => 500, 'clicks' => 10, 'ad_requests' => 1000,
            'viewable_impressions' => 400, 'measurable_impressions' => 450,
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.index'));

        $response->assertOk();
        $response->assertSee('id="tas-export-pdf-btn"', false);
        $response->assertSee('id="tas-export-csv-btn"', false);
        // The IDs buildDashboardCsv() reads via getElementById() — a
        // rename of any of these silently breaks the CSV export with no
        // visible error, so their presence is worth asserting directly.
        $response->assertSee('id="tas-stat-users-now"', false);
        $response->assertSee('id="tas-stat-teams-now"', false);
        $response->assertSee('id="tas-sport-table"', false);
        $response->assertSee('id="tas-ad-impressions"', false);
        $response->assertSee('id="tas-ad-fill-rate"', false);
        $response->assertSee('id="tas-ad-ctr"', false);
        $response->assertSee('id="tas-ad-viewability"', false);
    }

    public function test_index_shows_current_totals_and_range_growth(): void
    {
        $this->snapshot(now()->subDays(10)->toDateTimeString(), 1000, 100, ['HOCKEY' => 60, 'SOCCER' => 40]);
        $this->snapshot(now()->toDateTimeString(), 1200, 130, ['HOCKEY' => 80, 'SOCCER' => 50]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.index'));

        $response->assertOk();
        $response->assertViewHas('curUsers', 1200);
        $response->assertViewHas('curTeams', 130);
        $response->assertViewHas('netUsers', 200);
        $response->assertViewHas('netTeams', 30);
        $response->assertViewHas('breakdown', function ($breakdown) {
            $hockey = collect($breakdown)->firstWhere('category', 'HOCKEY');

            return $hockey['teams'] === 80 && $hockey['growth'] === 20;
        });
    }

    public function test_index_reflects_ad_totals_and_ratios(): void
    {
        $this->snapshot(now()->toDateTimeString(), 1000, 100);
        TeamAppAdSnapshot::create([
            'date' => now()->toDateString(),
            'impressions' => 500, 'clicks' => 10, 'ad_requests' => 1000,
            'viewable_impressions' => 400, 'measurable_impressions' => 450,
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.index'));

        $response->assertOk();
        $response->assertViewHas('ads', function ($ads) {
            return $ads['totals']['impressions'] === 500
                && $ads['fillRate'] === 50.0
                && round($ads['ctr'], 1) === 2.0;
        });
    }

    public function test_empty_range_shows_no_data_yet(): void
    {
        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.index'));

        $response->assertOk();
        $response->assertSee('No snapshots in this range yet');
    }

    public function test_ga4_data_returns_the_clients_result_for_a_valid_range(): void
    {
        $mock = Mockery::mock(Ga4Client::class);
        $mock->shouldReceive('dashboardData')->once()->with('2026-08-01', '2026-08-31')->andReturn(['events' => 10]);
        $this->app->instance(Ga4Client::class, $mock);

        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.team-app-stats.ga4-data', ['from' => '2026-08-01', 'to' => '2026-08-31']));

        $response->assertOk()->assertJson(['events' => 10]);
    }

    public function test_ga4_data_rejects_a_malformed_date(): void
    {
        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.team-app-stats.ga4-data', ['from' => 'not-a-date', 'to' => '2026-08-31']));

        $response->assertStatus(400);
    }

    public function test_ga4_data_returns_502_when_the_client_fails(): void
    {
        $mock = Mockery::mock(Ga4Client::class);
        $mock->shouldReceive('dashboardData')->once()->andReturnNull();
        $this->app->instance(Ga4Client::class, $mock);

        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.team-app-stats.ga4-data', ['from' => '2026-08-01', 'to' => '2026-08-31']));

        $response->assertStatus(502);
    }

    public function test_ga4_realtime_returns_the_clients_result(): void
    {
        $mock = Mockery::mock(Ga4Client::class);
        $mock->shouldReceive('realtime')->once()->andReturn(['activeUsers' => 3, 'countries' => []]);
        $this->app->instance(Ga4Client::class, $mock);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.ga4-realtime'));

        $response->assertOk()->assertJson(['activeUsers' => 3]);
    }

    public function test_ga4_sponsor_data_returns_the_clients_result(): void
    {
        $mock = Mockery::mock(Ga4Client::class);
        $mock->shouldReceive('sponsorData')->once()->with('2026-08-01', '2026-08-31')->andReturn(['activeUsers' => 7]);
        $this->app->instance(Ga4Client::class, $mock);

        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.team-app-stats.ga4-sponsor-data', ['from' => '2026-08-01', 'to' => '2026-08-31']));

        $response->assertOk()->assertJson(['activeUsers' => 7]);
    }

    public function test_social_top_posts_returns_the_clients_result_for_the_default_period(): void
    {
        $mock = Mockery::mock(SocialInsightsClient::class);
        $mock->shouldReceive('topPosts')->once()->with('WEEK')->andReturn([['rank' => 1, 'postId' => 'p1']]);
        $this->app->instance(SocialInsightsClient::class, $mock);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.social.top-posts'));

        $response->assertOk()->assertJson(['data' => [['rank' => 1, 'postId' => 'p1']]]);
    }

    public function test_social_top_posts_rejects_an_invalid_period(): void
    {
        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.team-app-stats.social.top-posts', ['period' => 'YEAR']));

        $response->assertStatus(400);
    }

    public function test_social_top_posts_returns_502_when_the_client_fails(): void
    {
        $mock = Mockery::mock(SocialInsightsClient::class);
        $mock->shouldReceive('topPosts')->once()->andReturnNull();
        $this->app->instance(SocialInsightsClient::class, $mock);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.social.top-posts'));

        $response->assertStatus(502);
    }

    public function test_social_top_followed_returns_the_clients_result_for_a_chosen_period(): void
    {
        $mock = Mockery::mock(SocialInsightsClient::class);
        $mock->shouldReceive('topFollowed')->once()->with('MONTH')->andReturn([['rank' => 1, 'accountType' => 'TEAM']]);
        $this->app->instance(SocialInsightsClient::class, $mock);

        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.team-app-stats.social.top-followed', ['period' => 'MONTH']));

        $response->assertOk()->assertJson(['data' => [['rank' => 1, 'accountType' => 'TEAM']]]);
    }

    public function test_social_history_returns_empty_dates_when_nothing_has_been_captured_yet(): void
    {
        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.social.history'));

        $response->assertOk()->assertJson(['dates' => [], 'date' => null, 'posts' => [], 'followed' => []]);
    }

    public function test_social_history_defaults_to_the_most_recent_captured_date(): void
    {
        TeamAppSocialPostSnapshot::create([
            'captured_at' => '2026-09-05', 'period' => 'WEEK', 'rank' => 1, 'post_id' => 'p-old', 'interactions_in_period' => 5,
        ]);
        TeamAppSocialPostSnapshot::create([
            'captured_at' => '2026-09-08', 'period' => 'WEEK', 'rank' => 1, 'post_id' => 'p-new', 'interactions_in_period' => 9,
        ]);
        TeamAppSocialFollowSnapshot::create([
            'captured_at' => '2026-09-08', 'period' => 'WEEK', 'rank' => 1, 'account_type' => 'TEAM', 'new_followers' => 4,
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-app-stats.social.history'));

        $response->assertOk();
        $body = $response->json();
        $this->assertSame(['2026-09-08', '2026-09-05'], $body['dates']);
        $this->assertSame('2026-09-08', $body['date']);
        $this->assertCount(1, $body['posts']);
        $this->assertSame('p-new', $body['posts'][0]['post_id']);
        $this->assertCount(1, $body['followed']);
    }

    public function test_social_history_returns_a_specific_requested_date(): void
    {
        TeamAppSocialPostSnapshot::create([
            'captured_at' => '2026-09-05', 'period' => 'WEEK', 'rank' => 1, 'post_id' => 'p-old', 'interactions_in_period' => 5,
        ]);
        TeamAppSocialPostSnapshot::create([
            'captured_at' => '2026-09-08', 'period' => 'WEEK', 'rank' => 1, 'post_id' => 'p-new', 'interactions_in_period' => 9,
        ]);

        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.team-app-stats.social.history', ['date' => '2026-09-05']));

        $response->assertOk();
        $body = $response->json();
        $this->assertSame('2026-09-05', $body['date']);
        $this->assertSame('p-old', $body['posts'][0]['post_id']);
    }

    public function test_social_endpoints_are_forbidden_without_the_tool_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.team-app-stats.social.top-posts'))->assertForbidden();
        $this->actingAs($user)->get(route('tools.team-app-stats.social.top-followed'))->assertForbidden();
        $this->actingAs($user)->get(route('tools.team-app-stats.social.history'))->assertForbidden();
    }
}
