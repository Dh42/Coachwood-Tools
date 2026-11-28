<?php

namespace Tests\Feature\Console;

use App\Models\Order;
use App\Models\TeamSponsorshipActivity;
use App\Services\TeamSponsorship\TeamActivityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamSponsorshipRefreshActivityCommandTest extends TestCase
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

    private function fakeChain(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [
                    ['teamId' => 'team-a', 'teamName' => 'Team A', 'sportType' => 'HOCKEY', 'adminEmails' => ['a@example.com']],
                    ['teamId' => 'team-b', 'teamName' => 'Team B', 'sportType' => 'SOCCER', 'adminEmails' => ['b@example.com']],
                ],
                'metadata' => ['last' => true],
            ], 200),
            '*/api/users/search?q=a%40example.com*' => Http::response([['userId' => 'user-a', 'name' => 'A']], 200),
            '*/api/users/search?q=b%40example.com*' => Http::response([['userId' => 'user-b', 'name' => 'B']], 200),
            '*/api/admin/teams/all' => function ($request) {
                $email = $request['email'] ?? null;

                return match ($email) {
                    // emails_count = registered + staff + playerContacts + 1 = 10+2+1+1 = 14
                    'a@example.com' => Http::response(['content' => [['teamName' => 'Team A', 'numberOfRegisteredPlayers' => 10, 'numberOfStaff' => 2, 'numberOfPlayerContacts' => 1]], 'last' => true], 200),
                    // 20+3+0+1 = 24
                    'b@example.com' => Http::response(['content' => [['teamName' => 'Team B', 'numberOfRegisteredPlayers' => 20, 'numberOfStaff' => 3]], 'last' => true], 200),
                    default => Http::response(['content' => [], 'last' => true], 200),
                };
            },
            '*/api/users/user-a/teams/team-a/events' => Http::response(array_fill(0, 5, []), 200),
            '*/api/users/user-a/teams/team-a/games' => Http::response(array_fill(0, 2, []), 200),
            '*/api/users/user-a/teams/team-a/practices' => Http::response(array_fill(0, 3, []), 200),
            '*/api/users/user-b/teams/team-b/events' => Http::response(array_fill(0, 1, []), 200),
            '*/api/users/user-b/teams/team-b/games' => Http::response([], 200),
            '*/api/users/user-b/teams/team-b/practices' => Http::response([], 200),
        ]);
    }

    public function test_refresh_resolves_and_caches_activity_for_every_distinct_order_pair(): void
    {
        Order::create(['email' => 'a@example.com', 'team_name' => 'Team A', 'shopify_order_id' => 1, 'sent_at' => now()]);
        Order::create(['email' => 'b@example.com', 'team_name' => 'Team B', 'shopify_order_id' => 2, 'sent_at' => now()]);
        $this->fakeChain();

        $this->artisan('team-sponsorship:refresh-activity')->assertSuccessful();

        $this->assertSame(2, TeamSponsorshipActivity::count());

        $a = TeamSponsorshipActivity::where('email', 'a@example.com')->where('team_name', 'Team A')->firstOrFail();
        $this->assertSame('team-a', $a->team_id);
        $this->assertSame('user-a', $a->user_id);
        $this->assertSame(14, $a->emails_count); // 10 registered + 2 staff + 1 contact + 1 (coach)
        $this->assertSame(5, $a->events_count);
        $this->assertSame(2, $a->games_count);
        $this->assertSame(3, $a->practices_count);
        $this->assertNotNull($a->refreshed_at);
        $this->assertNull($a->last_error);

        $b = TeamSponsorshipActivity::where('email', 'b@example.com')->where('team_name', 'Team B')->firstOrFail();
        $this->assertSame(1, $b->events_count);
        $this->assertSame(0, $b->games_count);
    }

    public function test_counts_still_refresh_when_the_owner_cant_be_found_by_email(): void
    {
        // Real case (2026-09-24): /api/users/search returns nothing for some
        // owners' emails. The counts endpoints ignore the path userId, so
        // only the teamId is needed.
        $placeholder = TeamActivityClient::PLACEHOLDER_USER_ID;
        Order::create(['email' => 'c@example.com', 'team_name' => 'Team C', 'shopify_order_id' => 3, 'sent_at' => now()]);

        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [['teamId' => 'team-c', 'teamName' => 'Team C', 'sportType' => 'HOCKEY', 'adminEmails' => ['c@example.com']]],
                'metadata' => ['last' => true],
            ], 200),
            '*/api/users/search*' => Http::response([], 200),
            '*/api/admin/teams/all' => Http::response(['content' => [['teamName' => 'Team C', 'numberOfRegisteredPlayers' => 12]], 'last' => true], 200),
            "*/api/users/{$placeholder}/teams/team-c/events" => Http::response(array_fill(0, 33, []), 200),
            "*/api/users/{$placeholder}/teams/team-c/games" => Http::response(array_fill(0, 17, []), 200),
            "*/api/users/{$placeholder}/teams/team-c/practices" => Http::response(array_fill(0, 16, []), 200),
        ]);

        $this->artisan('team-sponsorship:refresh-activity')->assertSuccessful();

        $c = TeamSponsorshipActivity::where('email', 'c@example.com')->firstOrFail();
        $this->assertNull($c->user_id);
        $this->assertSame(33, $c->events_count);
        $this->assertSame(17, $c->games_count);
        $this->assertSame(16, $c->practices_count);
        // Still recorded, so an unresolvable owner stays visible for review.
        $this->assertStringContainsString('user_id not found', $c->last_error);
    }

    public function test_distinct_pairs_only_hit_the_user_search_and_teams_all_endpoints_once_per_email(): void
    {
        Order::create(['email' => 'a@example.com', 'team_name' => 'Team A', 'shopify_order_id' => 1, 'sent_at' => now()->subDay()]);
        Order::create(['email' => 'a@example.com', 'team_name' => 'Team A', 'shopify_order_id' => 2, 'sent_at' => now()]);
        $this->fakeChain();

        $this->artisan('team-sponsorship:refresh-activity')->assertSuccessful();

        $this->assertSame(1, TeamSponsorshipActivity::count());
        Http::assertSentCount(
            // login + sport-type page + one /api/users/search + one /api/admin/teams/all
            // + 3 count calls (events/games/practices) = 7, regardless of 2 orders sharing the pair.
            7
        );
    }

    public function test_a_failed_sub_lookup_does_not_blank_out_previously_good_data(): void
    {
        Order::create(['email' => 'a@example.com', 'team_name' => 'Team A', 'shopify_order_id' => 1, 'sent_at' => now()]);

        TeamSponsorshipActivity::create([
            'email' => 'a@example.com',
            'team_name' => 'Team A',
            'team_id' => 'team-a',
            'user_id' => 'user-a',
            'emails_count' => 99,
            'events_count' => 99,
            'games_count' => 99,
            'practices_count' => 99,
            'refreshed_at' => now()->subDay(),
        ]);

        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [
                    ['teamId' => 'team-a', 'teamName' => 'Team A', 'sportType' => 'HOCKEY', 'adminEmails' => ['a@example.com']],
                ],
                'metadata' => ['last' => true],
            ], 200),
            '*/api/users/search*' => Http::response([['userId' => 'user-a', 'name' => 'A']], 200),
            // emails_count = 15+1+2+1 = 19
            '*/api/admin/teams/all' => Http::response(['content' => [['teamName' => 'Team A', 'numberOfRegisteredPlayers' => 15, 'numberOfStaff' => 1, 'numberOfPlayerContacts' => 2]], 'last' => true], 200),
            '*/api/users/user-a/teams/team-a/events' => Http::response(array_fill(0, 7, []), 200),
            '*/api/users/user-a/teams/team-a/games' => Http::response('error', 500),
            '*/api/users/user-a/teams/team-a/practices' => Http::response(array_fill(0, 4, []), 200),
        ]);

        $this->artisan('team-sponsorship:refresh-activity')->assertSuccessful();

        $a = TeamSponsorshipActivity::where('email', 'a@example.com')->where('team_name', 'Team A')->firstOrFail();
        $this->assertSame(19, $a->emails_count);
        $this->assertSame(7, $a->events_count);
        $this->assertSame(99, $a->games_count); // unchanged — this run's games call failed
        $this->assertSame(4, $a->practices_count);
        $this->assertStringContainsString('games', $a->last_error);
    }
}
