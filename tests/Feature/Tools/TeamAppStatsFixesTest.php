<?php

namespace Tests\Feature\Tools;

use App\Models\TeamAppSocialPostSnapshot;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Fixes to the old BioSteel App Stats, each failing on the old code first (docs/found-issues.md). */
class TeamAppStatsFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function viewer(): User
    {
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo(Tool::where('slug', 'team-app-stats')->sole()->permissionName());

        return $user;
    }

    /**
     * Issue 12: the social snapshot runs at 23:55 Toronto, which is already
     * the next day in UTC, so the old code stamped tomorrow's date.
     */
    public function test_the_social_snapshot_is_stamped_with_the_toronto_date_it_ran_on(): void
    {
        config(['services.biosteel_teams.base_url' => 'https://api.biosteelteams.test']);
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/social/admin/insights/top-posts*' => Http::response(['data' => [['rank' => 1, 'postId' => 'post-1']]]),
            '*/api/social/admin/insights/top-followed*' => Http::response(['data' => []]),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-08 23:55:00', 'America/Toronto'));

        $this->artisan('team-app-stats:snapshot-social')->assertSuccessful();

        $this->assertSame('2026-09-08', TeamAppSocialPostSnapshot::sole()->captured_at);
    }

    /** Issue 20: a malformed date in the filter returned a 500. */
    public function test_a_malformed_date_falls_back_to_the_default_range_instead_of_a_500(): void
    {
        Carbon::setTestNow('2026-09-30 12:00:00');

        $this->actingAs($this->viewer())
            ->get(route('tools.team-app-stats.index', ['from' => 'not-a-date', 'to' => '2026-13-45']))
            ->assertOk()
            ->assertViewHas('from', '2026-08-31')
            ->assertViewHas('to', '2026-09-30');
    }

    /** Issue 20: a range given backwards is swapped rather than empty. */
    public function test_a_backwards_range_is_put_the_right_way_round(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('tools.team-app-stats.index', ['from' => '2026-09-10', 'to' => '2026-09-01']))
            ->assertOk()
            ->assertViewHas('from', '2026-09-01')
            ->assertViewHas('to', '2026-09-10');
    }
}
