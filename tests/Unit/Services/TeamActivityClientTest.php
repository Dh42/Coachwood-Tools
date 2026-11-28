<?php

namespace Tests\Unit\Services;

use App\Services\BioSteelTeams\BioSteelTeamsClient;
use App\Services\TeamSponsorship\TeamActivityClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamActivityClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.biosteel_teams.base_url' => 'https://api.biosteelteams.test',
            'services.biosteel_teams.username' => 'app@biosteel.com',
            'services.biosteel_teams.password' => 'secret',
        ]);
    }

    public function test_counts_returns_the_size_of_each_array(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/users/user-1/teams/team-1/events' => Http::response([[], [], []], 200),
            '*/api/users/user-1/teams/team-1/games' => Http::response([[]], 200),
            '*/api/users/user-1/teams/team-1/practices' => Http::response([], 200),
        ]);

        $counts = (new TeamActivityClient(new BioSteelTeamsClient))->counts('user-1', 'team-1');

        $this->assertSame(['events' => 3, 'games' => 1, 'practices' => 0], $counts);
    }

    public function test_a_failed_call_for_one_kind_leaves_only_that_count_null(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/users/user-1/teams/team-1/events' => Http::response([[], []], 200),
            '*/api/users/user-1/teams/team-1/games' => Http::response('error', 500),
            '*/api/users/user-1/teams/team-1/practices' => Http::response([[]], 200),
        ]);

        $counts = (new TeamActivityClient(new BioSteelTeamsClient))->counts('user-1', 'team-1');

        $this->assertSame(['events' => 2, 'games' => null, 'practices' => 1], $counts);
    }

    public function test_a_null_user_id_falls_back_to_the_placeholder_instead_of_skipping(): void
    {
        $placeholder = TeamActivityClient::PLACEHOLDER_USER_ID;

        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            "*/api/users/{$placeholder}/teams/team-1/*" => Http::response([[], []], 200),
        ]);

        $client = new TeamActivityClient(new BioSteelTeamsClient);

        $this->assertSame(['events' => 2, 'games' => 2, 'practices' => 2], $client->counts(null, 'team-1'));
        $this->assertSame(2, $client->eventsCount(null, 'team-1'));
    }
}
