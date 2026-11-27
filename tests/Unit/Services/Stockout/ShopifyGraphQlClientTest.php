<?php

namespace Tests\Unit\Services\Stockout;

use App\Services\Stockout\ShopifyGraphQlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class ShopifyGraphQlClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stockout.shopify.store' => 'shopify-test.myshopify.com',
            'stockout.shopify.client_id' => 'test-client-id',
            'stockout.shopify.client_secret' => 'test-client-secret',
            'stockout.shopify.access_token' => null,
            'stockout.api_version' => '2026-07',
        ]);
    }

    private function fakeTokenResponse(string $token = 'shpat_fetched', int $expiresIn = 86399): array
    {
        return ['access_token' => $token, 'scope' => 'read_products', 'expires_in' => $expiresIn];
    }

    public function test_it_fetches_and_caches_a_token_via_client_credentials_grant_when_none_is_configured(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/admin/oauth/access_token' => Http::response($this->fakeTokenResponse('shpat_fetched')),
            'shopify-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['shop' => ['name' => 'BioSteel']]]),
        ]);

        $result = (new ShopifyGraphQlClient)->query('{ shop { name } }');

        $this->assertSame(['shop' => ['name' => 'BioSteel']], $result['data']);

        Http::assertSent(fn ($request) => $request->url() === 'https://shopify-test.myshopify.com/admin/oauth/access_token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'test-client-id'
            && $request['client_secret'] === 'test-client-secret');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Shopify-Access-Token', 'shpat_fetched')
            && str_contains($request->url(), '/graphql.json'));
    }

    public function test_a_second_query_reuses_the_cached_token_without_fetching_again(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/admin/oauth/access_token' => Http::response($this->fakeTokenResponse()),
            'shopify-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['shop' => ['name' => 'BioSteel']]]),
        ]);

        $client = new ShopifyGraphQlClient;
        $client->query('{ shop { name } }');
        $client->query('{ shop { name } }');

        Http::assertSentCount(3); // 1 token fetch + 2 graphql calls
    }

    public function test_a_configured_static_access_token_skips_the_token_fetch_entirely(): void
    {
        config(['stockout.shopify.access_token' => 'shpat_static']);

        Http::fake([
            'shopify-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(['data' => ['shop' => ['name' => 'BioSteel']]]),
        ]);

        (new ShopifyGraphQlClient)->query('{ shop { name } }');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Shopify-Access-Token', 'shpat_static'));
    }

    public function test_a_401_clears_the_cached_token_and_retries_once_with_a_fresh_one(): void
    {
        Cache::put('stockout:shopify:access_token', 'shpat_stale', now()->addHour());

        Http::fake([
            'shopify-test.myshopify.com/admin/oauth/access_token' => Http::response($this->fakeTokenResponse('shpat_fresh')),
            'shopify-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push('Unauthorized', 401)
                ->push(['data' => ['shop' => ['name' => 'BioSteel']]]),
        ]);

        $result = (new ShopifyGraphQlClient)->query('{ shop { name } }');

        $this->assertSame(['shop' => ['name' => 'BioSteel']], $result['data']);
        Http::assertSentCount(3); // stale-token graphql call (401) + token fetch + retried graphql call

        Http::assertSent(fn ($request) => str_contains($request->url(), '/graphql.json')
            && $request->hasHeader('X-Shopify-Access-Token', 'shpat_fresh'));
    }

    public function test_missing_client_credentials_throws_a_clear_error(): void
    {
        config(['stockout.shopify.client_id' => null, 'stockout.shopify.client_secret' => null]);

        $this->expectException(RuntimeException::class);

        (new ShopifyGraphQlClient)->query('{ shop { name } }');
    }

    /**
     * Regression test for a real bug: shopifyqlQuery is metered by a
     * separate, fixed-window analytics bucket reported inside the THROTTLED
     * error's own extensions.cost (windowResetAt), not the response's
     * top-level extensions.cost. Confirmed live 2026-09-27 that reading the
     * (healthy) top-level bucket instead computed a near-zero delay and
     * burned every retry attempt inside the same rate-limit window.
     */
    public function test_a_windowresetat_throttle_waits_for_the_reset_rather_than_the_healthy_top_level_bucket(): void
    {
        config(['stockout.shopify.access_token' => 'test-token']);

        Http::fake([
            'shopify-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push([
                    'errors' => [[
                        'message' => 'Rate limited. Please retry later.',
                        'extensions' => [
                            'code' => 'THROTTLED',
                            'cost' => [
                                'requestedQueryCost' => 21,
                                'maximumAvailable' => 1000,
                                'currentlyAvailable' => 13,
                                'windowResetAt' => now()->addSecond()->toIso8601String(),
                            ],
                        ],
                    ]],
                    // The healthy, misleading top-level bucket — confirmed
                    // live to stay near-full the entire time the analytics
                    // bucket above was empty.
                    'extensions' => ['cost' => ['requestedQueryCost' => 2, 'actualQueryCost' => 1, 'throttleStatus' => ['maximumAvailable' => 20000, 'currentlyAvailable' => 19999, 'restoreRate' => 1000]]],
                ], 200)
                ->push(['data' => ['shopifyqlQuery' => ['tableData' => ['rows' => []]]]], 200),
        ]);

        $start = microtime(true);
        $result = (new ShopifyGraphQlClient)->query('{ shopifyqlQuery(query: "...") { tableData { rows } } }');
        $elapsed = microtime(true) - $start;

        $this->assertNull($result['errors']);
        Http::assertSentCount(2);
        // Waited roughly until windowResetAt (~1s) + the 500ms buffer, not
        // the near-zero delay the top-level bucket would have produced.
        $this->assertGreaterThanOrEqual(1.0, $elapsed);
    }

    public function test_a_connect_timeout_is_retried_and_the_query_succeeds(): void
    {
        Sleep::fake();
        config(['stockout.shopify.access_token' => 'shpat_configured']);
        Http::fake([
            'shopify-test.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->pushFailedConnection('cURL error 28: Failed to connect to shopify-test.myshopify.com port 443 after 10002 ms: Timeout was reached')
                ->push(['data' => ['shop' => ['name' => 'BioSteel']]], 200),
        ]);

        $result = (new ShopifyGraphQlClient)->query('{ shop { name } }');

        $this->assertSame(['shop' => ['name' => 'BioSteel']], $result['data']);
        Http::assertSentCount(2);
    }
}
