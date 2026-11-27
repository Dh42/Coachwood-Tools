<?php

namespace Tests\Feature\Console;

use App\Models\TeamAppSocialFollowSnapshot;
use App\Models\TeamAppSocialPostSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from the old app. Changed on purpose: rows are now stamped with the
 * Toronto date the task runs on (found issue 12), so "today" here is
 * now('America/Toronto') instead of the UTC today().
 */
class TeamAppStatsSnapshotSocialCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.biosteel_teams.base_url' => 'https://api.biosteelteams.test',
            'services.biosteel_teams.username' => 'app@biosteel.com',
            'services.biosteel_teams.password' => 'secret',
        ]);
    }

    private function fakeHappyPath(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/social/admin/insights/top-posts*' => Http::response(['data' => [
                [
                    'rank' => 1, 'postId' => 'post-1', 'postType' => 'CLIP', 'visibility' => 'PUBLIC',
                    'createdAt' => '2026-09-01T12:00:00Z', 'text' => 'hello world',
                    'authorUserId' => 'user-1', 'authorName' => 'Jane Doe', 'authorUsername' => 'jdoe',
                    'teamId' => 'team-1', 'teamName' => 'Toronto Raptors', 'publishedInPeriod' => true,
                    'likesInPeriod' => 10, 'reactionsInPeriod' => 12, 'commentsInPeriod' => 3,
                    'interactionsInPeriod' => 15, 'lastInteractionAt' => '2026-09-08T09:30:00Z',
                    'viewersInPeriod' => 500, 'totalReactions' => 100, 'totalComments' => 20, 'totalViewCount' => 5000,
                ],
            ]]),
            '*/api/social/admin/insights/top-followed*' => Http::response(['data' => [
                [
                    'rank' => 1, 'accountType' => 'TEAM', 'accountId' => 'team-1', 'displayName' => 'Toronto Raptors',
                    'sportType' => 'BASKETBALL', 'newFollowers' => 25, 'totalFollowers' => 1200,
                ],
            ]]),
        ]);
    }

    public function test_captures_both_leaderboards_for_today_with_week_period(): void
    {
        $this->fakeHappyPath();

        $this->artisan('team-app-stats:snapshot-social')->assertSuccessful();

        $post = TeamAppSocialPostSnapshot::first();
        $this->assertNotNull($post);
        $this->assertSame(now('America/Toronto')->toDateString(), $post->captured_at);
        $this->assertSame('WEEK', $post->period);
        $this->assertSame('post-1', $post->post_id);
        $this->assertSame('Toronto Raptors', $post->team_name);
        $this->assertSame(15, $post->interactions_in_period);
        $this->assertSame('2026-09-01 12:00:00', $post->post_created_at->toDateTimeString());

        $followed = TeamAppSocialFollowSnapshot::first();
        $this->assertNotNull($followed);
        $this->assertSame('TEAM', $followed->account_type);
        $this->assertSame(25, $followed->new_followers);
    }

    public function test_rerunning_the_same_day_replaces_rather_than_duplicates(): void
    {
        $this->fakeHappyPath();

        $this->artisan('team-app-stats:snapshot-social')->assertSuccessful();
        $this->artisan('team-app-stats:snapshot-social')->assertSuccessful();

        $this->assertSame(1, TeamAppSocialPostSnapshot::count());
        $this->assertSame(1, TeamAppSocialFollowSnapshot::count());
    }

    /**
     * Each report is independent — a failure fetching one must not block
     * writing the other (same principle this app applies to every other
     * "ancillary lookup" throughout — a broken sub-fetch degrades, it
     * doesn't take down a working one).
     */
    public function test_one_reports_failure_does_not_block_the_other_from_being_stored(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/social/admin/insights/top-posts*' => Http::response('error', 500),
            '*/api/social/admin/insights/top-followed*' => Http::response(['data' => [
                ['rank' => 1, 'accountType' => 'USER', 'accountId' => 'u1', 'displayName' => 'A User', 'newFollowers' => 3, 'totalFollowers' => 10],
            ]]),
        ]);

        $this->artisan('team-app-stats:snapshot-social')->assertFailed();

        $this->assertSame(0, TeamAppSocialPostSnapshot::count());
        $this->assertSame(1, TeamAppSocialFollowSnapshot::count());
    }

    public function test_an_empty_leaderboard_clears_any_existing_rows_for_today_without_erroring(): void
    {
        // A single Http::fake() call for the whole test, with a two-response
        // sequence per endpoint — calling Http::fake() a second time later in
        // the same test does NOT override an already-registered overlapping
        // pattern (the first-registered stub keeps matching; see
        // PoForecastXlsxParserTest's own note on this), so a sequence is the
        // correct way to simulate "this endpoint returns something different
        // on a later call" within one test.
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/social/admin/insights/top-posts*' => Http::sequence()
                ->push(['data' => [
                    ['rank' => 1, 'postId' => 'post-1', 'authorName' => 'Jane Doe', 'interactionsInPeriod' => 15],
                ]])
                ->push(['data' => []]),
            '*/api/social/admin/insights/top-followed*' => Http::sequence()
                ->push(['data' => [
                    ['rank' => 1, 'accountType' => 'TEAM', 'accountId' => 'team-1', 'displayName' => 'Toronto Raptors', 'newFollowers' => 25],
                ]])
                ->push(['data' => []]),
        ]);

        $this->artisan('team-app-stats:snapshot-social')->assertSuccessful();
        $this->assertSame(1, TeamAppSocialPostSnapshot::count());
        $this->assertSame(1, TeamAppSocialFollowSnapshot::count());

        $this->artisan('team-app-stats:snapshot-social')->assertSuccessful();

        $this->assertSame(0, TeamAppSocialPostSnapshot::count());
        $this->assertSame(0, TeamAppSocialFollowSnapshot::count());
    }
}
