<?php

namespace Tests\Unit\Services;

use App\Services\WalmartUs\WalmartUsApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalmartUsApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'walmart.us.base_url' => 'https://marketplace.walmartapis.com/v3',
            'walmart.us.client_id' => 'test-client-id',
            'walmart.us.client_secret' => 'test-client-secret',
            'walmart.us.max_retries' => 5,
            // Keep the fallback backoff effectively instant so a
            // 429-then-success test doesn't slow the suite down.
            'walmart.us.base_backoff_ms' => 1,
            'walmart.us.max_backoff_ms' => 5,
        ]);
    }

    public function test_it_exchanges_and_caches_the_access_token(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::response(['items' => []]),
        ]);

        $client = new WalmartUsApiClient;
        $client->getItems();
        $client->getItems();

        Http::assertSentCount(3); // 1 token exchange + 2 item calls — token reused on the second call.
    }

    public function test_every_request_carries_correlation_and_service_headers(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::response(['items' => []]),
        ]);

        $result = (new WalmartUsApiClient)->getItems();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://marketplace.walmartapis.com/v3/items'
                && $request->hasHeader('WM_SVC.NAME', 'Walmart Marketplace')
                && $request->hasHeader('WM_QOS.CORRELATION_ID');
        });

        $this->assertNotEmpty($result['correlation_id']);
    }

    public function test_it_retries_on_429_with_backoff_then_succeeds(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::sequence()
                ->push(['error' => 'rate limited'], 429)
                ->push(['error' => 'rate limited'], 429)
                ->push(['items' => [['sku' => 'ABC123']]], 200),
        ]);

        $result = (new WalmartUsApiClient)->getItems();

        $this->assertSame(200, $result['status']);
        $this->assertSame([['sku' => 'ABC123']], $result['body']['items']);
        Http::assertSentCount(4); // token + 2 failed attempts + 1 success
    }

    public function test_it_gives_up_after_max_retries_on_persistent_429(): void
    {
        config(['walmart.us.max_retries' => 2]);

        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $result = (new WalmartUsApiClient)->getItems();

        $this->assertSame(429, $result['status']);
        Http::assertSentCount(4); // token + 1 initial + 2 retries
    }
}
