<?php

namespace Tests\Unit\Services\TeamAppStats;

use App\Services\TeamAppStats\SocialInsightsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialInsightsClientTest extends TestCase
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

    public function test_top_posts_returns_the_data_array_and_sends_the_period_and_bearer_token(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer test-token']),
            '*/api/social/admin/insights/top-posts*' => Http::response([
                'success' => true,
                'data' => [['rank' => 1, 'postId' => 'p1', 'interactionsInPeriod' => 42]],
            ], 200),
        ]);

        $result = app(SocialInsightsClient::class)->topPosts('MONTH', 90, 10);

        $this->assertSame([['rank' => 1, 'postId' => 'p1', 'interactionsInPeriod' => 42]], $result);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/api/social/admin/insights/top-posts')
            && str_contains((string) $request->url(), 'period=MONTH')
            && str_contains((string) $request->url(), 'lookbackDays=90')
            && str_contains((string) $request->url(), 'limit=10')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_top_posts_returns_null_on_a_failed_request(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/social/admin/insights/top-posts*' => Http::response('error', 500),
        ]);

        $this->assertNull(app(SocialInsightsClient::class)->topPosts());
    }

    public function test_top_followed_returns_the_data_array(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/social/admin/insights/top-followed*' => Http::response([
                'data' => [['rank' => 1, 'accountType' => 'TEAM', 'newFollowers' => 5]],
            ], 200),
        ]);

        $result = app(SocialInsightsClient::class)->topFollowed('WEEK', 25);

        $this->assertSame([['rank' => 1, 'accountType' => 'TEAM', 'newFollowers' => 5]], $result);
    }

    public function test_top_followed_returns_null_on_a_failed_request(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/social/admin/insights/top-followed*' => Http::response('error', 500),
        ]);

        $this->assertNull(app(SocialInsightsClient::class)->topFollowed());
    }

    public function test_returns_null_when_the_data_key_is_missing_or_not_an_array(): void
    {
        Http::fake([
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            '*/api/social/admin/insights/top-posts*' => Http::response(['success' => true], 200),
        ]);

        $this->assertNull(app(SocialInsightsClient::class)->topPosts());
    }
}
