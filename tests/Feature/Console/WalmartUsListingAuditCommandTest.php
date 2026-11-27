<?php

namespace Tests\Feature\Console;

use App\Models\WalmartUsItem;
use App\Models\WalmartUsListingAudit;
use App\Models\WalmartUsListingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalmartUsListingAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'walmart.us.base_url' => 'https://marketplace.walmartapis.com/v3',
            'walmart.us.client_id' => 'test-client-id',
            'walmart.us.client_secret' => 'test-client-secret',
        ]);
    }

    protected function fakeHappyPath(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::response([
                'ItemResponse' => [['sku' => 'SKU-1', 'productName' => 'Widget One']],
                'totalItems' => 1,
            ]),
            'marketplace.walmartapis.com/v3/reports/reportRequests?*' => Http::response(['requestId' => 'req-1'], 200),
            'marketplace.walmartapis.com/v3/reports/reportRequests/req-1' => Http::response(['requestStatus' => 'READY', 'reportId' => 'rep-1']),
            'marketplace.walmartapis.com/v3/reports/reportRequests/rep-1/download' => Http::response(json_encode([
                'items' => [['sku' => 'SKU-1', 'asOfDate' => '2026-09-01', 'listingQuality' => 82.5, 'issues' => []]],
            ])),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/score' => Http::response([
                'payload' => ['listingQuality' => 80, 'score' => [], 'postPurchaseQuality' => []],
                'status' => 'OK',
            ]),
        ]);
    }

    public function test_a_live_run_syncs_the_catalog_and_writes_audit_rows(): void
    {
        $this->fakeHappyPath();

        $this->artisan('walmart-us:listing-audit')->assertExitCode(0);

        $this->assertDatabaseHas('walmart_us_items', ['sku' => 'SKU-1']);
        $this->assertDatabaseHas('walmart_us_listing_audits', ['sku' => 'SKU-1']);
    }

    public function test_dry_run_writes_nothing_to_the_database(): void
    {
        $this->fakeHappyPath();

        $this->artisan('walmart-us:listing-audit', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, WalmartUsItem::count());
        $this->assertSame(0, WalmartUsListingAudit::count());
        $this->assertSame(0, WalmartUsListingSnapshot::count());
    }

    public function test_sku_option_skips_the_catalog_sync(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/reports/reportRequests?*' => Http::response(['requestId' => 'req-1'], 200),
            'marketplace.walmartapis.com/v3/reports/reportRequests/req-1' => Http::response(['requestStatus' => 'READY', 'reportId' => 'rep-1']),
            'marketplace.walmartapis.com/v3/reports/reportRequests/rep-1/download' => Http::response(json_encode([
                'items' => [['sku' => 'SKU-1', 'asOfDate' => '2026-09-01', 'listingQuality' => 82.5, 'issues' => []]],
            ])),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/score' => Http::response([
                'payload' => ['listingQuality' => 80, 'score' => [], 'postPurchaseQuality' => []],
                'status' => 'OK',
            ]),
        ]);

        $this->artisan('walmart-us:listing-audit', ['--sku' => 'SKU-1'])->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v3/items'));
        $this->assertDatabaseHas('walmart_us_listing_audits', ['sku' => 'SKU-1']);
    }

    public function test_it_fails_gracefully_when_the_api_call_errors(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->artisan('walmart-us:listing-audit')->assertExitCode(1);
    }
}
