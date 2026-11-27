<?php

namespace Tests\Feature\Console;

use App\Models\TeamAppCategorySnapshot;
use App\Models\TeamAppSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamAppStatsSnapshotCommandTest extends TestCase
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

        // Deliberately NOT faking email-count/teams-count here too: Http::fake()
        // calls merge (append) rather than replace, and the first matching stub
        // in registration order wins — a happy-path default set here would
        // silently win over a later, more specific override in a failure test.
        // Each test method fakes its own complete set instead.
    }

    private function fakeHappyPath(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/users/email-count' => Http::response(['data' => 5000]),
            '*/api/admin/teams/count' => Http::response([
                'data' => ['totalTeams' => 300, 'teamsBySport' => ['HOCKEY' => 200, 'SOCCER' => 100]],
            ]),
        ]);
    }

    public function test_snapshot_writes_one_hourly_row_and_its_category_breakdown(): void
    {
        $this->fakeHappyPath();

        $this->artisan('team-app-stats:snapshot')->assertSuccessful();

        $snapshot = TeamAppSnapshot::first();
        $this->assertNotNull($snapshot);
        $this->assertSame(5000, $snapshot->users);
        $this->assertSame(300, $snapshot->teams);
        $this->assertSame(now()->startOfHour()->toDateTimeString(), $snapshot->captured_at->toDateTimeString());

        $this->assertSame(2, TeamAppCategorySnapshot::where('snapshot_id', $snapshot->id)->count());
        $this->assertSame(200, TeamAppCategorySnapshot::where('snapshot_id', $snapshot->id)->where('category', 'HOCKEY')->value('teams'));
    }

    public function test_rerunning_within_the_same_hour_overwrites_rather_than_duplicates(): void
    {
        $this->fakeHappyPath();

        $this->artisan('team-app-stats:snapshot')->assertSuccessful();
        $this->artisan('team-app-stats:snapshot')->assertSuccessful();

        $this->assertSame(1, TeamAppSnapshot::count());
    }

    public function test_fails_gracefully_when_the_api_returns_no_usable_data(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/users/email-count' => Http::response('error', 500),
            '*/api/admin/teams/count' => Http::response(['data' => ['totalTeams' => 300, 'teamsBySport' => []]]),
        ]);

        $this->artisan('team-app-stats:snapshot')->assertFailed();

        $this->assertSame(0, TeamAppSnapshot::count());
    }
}
