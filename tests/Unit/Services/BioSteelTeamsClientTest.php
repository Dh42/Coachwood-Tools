<?php

namespace Tests\Unit\Services;

use App\Services\BioSteelTeams\BioSteelTeamsClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BioSteelTeamsClientTest extends TestCase
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

    public function test_it_reads_the_bearer_token_from_the_response_header_not_the_body(): void
    {
        Http::fake([
            'api.biosteelteams.test/api/users/login' => Http::response(
                ['isAssociationUser' => false],
                200,
                ['Authorization' => 'Bearer test-token-123']
            ),
            'api.biosteelteams.test/api/admin/teams/all' => Http::response([
                ['teamName' => 'Nashville Ravens'],
            ], 200),
        ]);

        $teams = (new BioSteelTeamsClient)->teamsForEmail('lesley@biosteel.com');

        $this->assertSame([['teamName' => 'Nashville Ravens']], $teams);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.biosteelteams.test/api/admin/teams/all'
                && $request->hasHeader('Authorization', 'Bearer test-token-123');
        });
    }

    public function test_a_plain_array_response_is_used_directly_with_no_pagination(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/all' => Http::response([
                ['teamName' => 'A'], ['teamName' => 'B'],
            ], 200),
        ]);

        $teams = (new BioSteelTeamsClient)->teamsForEmail('x@example.com');

        $this->assertCount(2, $teams);
    }

    public function test_paginated_content_responses_are_merged_across_pages(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/all?page=1&size=50' => Http::response([
                'content' => [['teamName' => 'Page 2 Team']],
                'last' => true,
            ], 200),
            '*/api/admin/teams/all' => Http::response([
                'content' => [['teamName' => 'Page 1 Team']],
                'last' => false,
            ], 200),
        ]);

        $teams = (new BioSteelTeamsClient)->teamsForEmail('x@example.com');

        $this->assertCount(2, $teams);
        $this->assertSame('Page 1 Team', $teams[0]['teamName']);
        $this->assertSame('Page 2 Team', $teams[1]['teamName']);
    }

    public function test_a_failed_pagination_continuation_returns_partial_results_instead_of_erroring(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/all?page=1&size=50' => Http::response('error', 500),
            '*/api/admin/teams/all' => Http::response([
                'content' => [['teamName' => 'Only Team']],
                'last' => false,
            ], 200),
        ]);

        $teams = (new BioSteelTeamsClient)->teamsForEmail('x@example.com');

        $this->assertSame([['teamName' => 'Only Team']], $teams);
    }

    public function test_a_failed_initial_request_throws(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/all' => Http::response('error', 500),
        ]);

        $this->expectException(RuntimeException::class);

        (new BioSteelTeamsClient)->teamsForEmail('x@example.com');
    }

    public function test_a_missing_bearer_token_throws(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response(['isAssociationUser' => false], 200),
        ]);

        $this->expectException(RuntimeException::class);

        (new BioSteelTeamsClient)->teamsForEmail('x@example.com');
    }

    public function test_sport_types_for_email_only_includes_teams_whose_admin_emails_contain_the_searched_email(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [
                    ['teamName' => 'Toronto Maple Leafs', 'sportType' => 'HOCKEY', 'adminEmails' => ['lesley@biosteel.com']],
                    ['teamName' => 'Some Other Team', 'sportType' => 'BASEBALL', 'adminEmails' => ['someone-else@example.com']],
                ],
                'metadata' => ['last' => true],
            ], 200),
        ]);

        $sportTypes = (new BioSteelTeamsClient)->sportTypesForEmail('lesley@biosteel.com');

        $this->assertSame(['toronto maple leafs' => 'HOCKEY'], $sportTypes);
    }

    public function test_sport_types_for_email_matches_case_insensitively_and_normalizes_smart_apostrophes(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [
                    ['teamName' => 'Women’s Hockey Club', 'sportType' => 'HOCKEY', 'adminEmails' => ['Lesley@BioSteel.com']],
                ],
                'metadata' => ['last' => true],
            ], 200),
        ]);

        $sportTypes = (new BioSteelTeamsClient)->sportTypesForEmail('lesley@biosteel.com');

        $this->assertSame(['women\'s hockey club' => 'HOCKEY'], $sportTypes);
    }

    public function test_sport_types_for_email_merges_across_paginated_pages(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails?page=1&size=500' => Http::response([
                'content' => [['teamName' => 'Page 2 Team', 'sportType' => 'SOCCER', 'adminEmails' => ['x@example.com']]],
                'metadata' => ['last' => true],
            ], 200),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [['teamName' => 'Page 1 Team', 'sportType' => 'HOCKEY', 'adminEmails' => ['x@example.com']]],
                'metadata' => ['last' => false],
            ], 200),
        ]);

        $sportTypes = (new BioSteelTeamsClient)->sportTypesForEmail('x@example.com');

        $this->assertSame(['page 1 team' => 'HOCKEY', 'page 2 team' => 'SOCCER'], $sportTypes);
    }

    public function test_sport_types_for_email_returns_empty_instead_of_throwing_on_a_failed_request(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response('error', 500),
        ]);

        $sportTypes = (new BioSteelTeamsClient)->sportTypesForEmail('x@example.com');

        $this->assertSame([], $sportTypes);
    }

    public function test_email_count_reads_the_total_from_the_data_envelope(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/users/email-count' => Http::response(['data' => 4321], 200),
        ]);

        $this->assertSame(4321, (new BioSteelTeamsClient)->emailCount());
    }

    public function test_email_count_returns_null_on_a_failed_request(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/users/email-count' => Http::response('error', 500),
        ]);

        $this->assertNull((new BioSteelTeamsClient)->emailCount());
    }

    public function test_teams_by_sport_reads_the_total_and_breakdown_from_the_data_envelope(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/count' => Http::response([
                'data' => ['totalTeams' => 150, 'teamsBySport' => ['HOCKEY' => 90, 'SOCCER' => 60]],
            ], 200),
        ]);

        $result = (new BioSteelTeamsClient)->teamsBySport();

        $this->assertSame(['total' => 150, 'bySport' => ['HOCKEY' => 90, 'SOCCER' => 60]], $result);
    }

    public function test_teams_by_sport_returns_null_on_a_failed_request(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/count' => Http::response('error', 500),
        ]);

        $this->assertNull((new BioSteelTeamsClient)->teamsBySport());
    }

    public function test_all_team_admin_email_rows_returns_raw_unfiltered_rows_merged_across_pages(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails?page=1&size=500' => Http::response([
                'content' => [
                    ['teamId' => 'team-2', 'teamName' => 'Page 2 Team', 'sportType' => 'SOCCER', 'adminEmails' => ['b@example.com']],
                ],
                'metadata' => ['last' => true],
            ], 200),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [
                    ['teamId' => 'team-1', 'teamName' => 'Page 1 Team', 'sportType' => 'HOCKEY', 'adminEmails' => ['A@Example.com']],
                ],
                'metadata' => ['last' => false],
            ], 200),
        ]);

        $rows = (new BioSteelTeamsClient)->allTeamAdminEmailRows();

        $this->assertCount(2, $rows);
        $this->assertSame('team-1', $rows[0]['teamId']);
        $this->assertSame('Page 1 Team', $rows[0]['teamName']);
        $this->assertSame('HOCKEY', $rows[0]['sportType']);
        // Lowercased/trimmed at read time, same as sportTypesForEmail()'s own matching.
        $this->assertSame(['a@example.com'], $rows[0]['adminEmails']);
        $this->assertSame('team-2', $rows[1]['teamId']);
    }

    public function test_all_team_admin_email_rows_returns_empty_on_a_failed_request(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/admin/teams/sport-type-admin-emails*' => Http::response('error', 500),
        ]);

        $this->assertSame([], (new BioSteelTeamsClient)->allTeamAdminEmailRows());
    }

    public function test_find_user_id_by_email_returns_the_id_for_exactly_one_match(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/users/search*' => Http::response([
                ['userId' => 'user-123', 'name' => 'Jane Doe', 'username' => 'janedoe'],
            ], 200),
        ]);

        $this->assertSame('user-123', (new BioSteelTeamsClient)->findUserIdByEmail('jane@example.com'));

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'q=jane%40example.com'));
    }

    public function test_find_user_id_by_email_returns_null_for_zero_matches(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/users/search*' => Http::response([], 200),
        ]);

        $this->assertNull((new BioSteelTeamsClient)->findUserIdByEmail('nobody@example.com'));
    }

    public function test_find_user_id_by_email_returns_null_for_ambiguous_multiple_matches(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/users/search*' => Http::response([
                ['userId' => 'user-1', 'name' => 'Jane Doe', 'username' => 'jane1'],
                ['userId' => 'user-2', 'name' => 'Jane Doe', 'username' => 'jane2'],
            ], 200),
        ]);

        $this->assertNull((new BioSteelTeamsClient)->findUserIdByEmail('jane@example.com'));
    }

    public function test_find_user_id_by_email_returns_null_on_a_failed_request(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/users/search*' => Http::response('error', 500),
        ]);

        $this->assertNull((new BioSteelTeamsClient)->findUserIdByEmail('jane@example.com'));
    }
}
