<?php

namespace Tests\Unit\Services;

use App\Models\WalmartUsItem;
use App\Services\WalmartUs\WalmartUsApiClient;
use App\Services\WalmartUs\WalmartUsCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalmartUsCatalogServiceTest extends TestCase
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

        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
        ]);
    }

    public function test_it_pages_through_items_and_upserts_them(): void
    {
        // Real shape confirmed live (2026-09-03): root key is `ItemResponse`,
        // pagination is plain offset/limit driven by `totalItems` — no
        // cursor is ever returned. $limit=1 here forces syncItems() to
        // actually page across two calls despite the tiny fixture.
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::sequence()
                ->push([
                    'ItemResponse' => [
                        ['sku' => 'SKU-1', 'wpid' => 'WPID-1', 'gtin' => '111', 'productName' => 'Widget One', 'publishedStatus' => 'PUBLISHED', 'lifecycleStatus' => 'ACTIVE', 'price' => ['amount' => 9.99]],
                    ],
                    'totalItems' => 2,
                ])
                ->push([
                    'ItemResponse' => [
                        ['sku' => 'SKU-2', 'wpid' => 'WPID-2', 'gtin' => '222', 'productName' => 'Widget Two', 'publishedStatus' => 'PUBLISHED', 'lifecycleStatus' => 'ACTIVE', 'price' => ['amount' => 19.99]],
                    ],
                    'totalItems' => 2,
                ]),
        ]);

        $count = (new WalmartUsCatalogService(new WalmartUsApiClient))->syncItems(limit: 1);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('walmart_us_items', ['sku' => 'SKU-1', 'wpid' => 'WPID-1', 'product_name' => 'Widget One']);
        $this->assertDatabaseHas('walmart_us_items', ['sku' => 'SKU-2', 'wpid' => 'WPID-2', 'product_name' => 'Widget Two']);
    }

    public function test_a_second_sync_updates_existing_items_rather_than_duplicating(): void
    {
        WalmartUsItem::create(['sku' => 'SKU-1', 'product_name' => 'Stale Name']);

        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::response([
                'ItemResponse' => [
                    ['sku' => 'SKU-1', 'productName' => 'Fresh Name'],
                ],
                'totalItems' => 1,
            ]),
        ]);

        (new WalmartUsCatalogService(new WalmartUsApiClient))->syncItems();

        $this->assertSame(1, WalmartUsItem::where('sku', 'SKU-1')->count());
        $this->assertDatabaseHas('walmart_us_items', ['sku' => 'SKU-1', 'product_name' => 'Fresh Name']);
    }

    public function test_it_throws_when_get_items_fails(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items*' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->expectException(\RuntimeException::class);

        (new WalmartUsCatalogService(new WalmartUsApiClient))->syncItems();
    }

    public function test_item_count_by_status_requires_and_passes_a_status_param(): void
    {
        // Real shape confirmed live: a bare call 400s ("mandatory
        // parameter(status) is missing"); with `status` it returns a flat
        // single-entry map like {"PUBLISHED": 26}.
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/items/count*' => Http::response(['PUBLISHED' => 26]),
        ]);

        $counts = (new WalmartUsCatalogService(new WalmartUsApiClient))->itemCountByStatus('PUBLISHED');

        $this->assertSame(['PUBLISHED' => 26], $counts);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'status=PUBLISHED'));
    }
}
