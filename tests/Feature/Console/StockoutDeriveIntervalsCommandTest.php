<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockoutDeriveIntervalsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stockout.shopify.store' => 'shopify-test.myshopify.com',
            'stockout.shopify.access_token' => 'test-token',
            'stockout.api_version' => '2026-07',
            'stockout.excluded_product_titles' => [],
            'stockout.excluded_variant_ids' => [],
        ]);
    }

    public function test_it_derives_intervals_from_seeded_daily_history(): void
    {
        DB::table('inventory_history_daily')->insert([
            'product_title' => 'BIOSTEEL HOODIE',
            'day' => '2026-01-01',
            'ending_inventory_units' => -1,
            'ingested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response(['data' => ['products' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => ''],
                'nodes' => [[
                    'title' => 'BIOSTEEL HOODIE',
                    'status' => 'ACTIVE',
                    'productType' => 'Sports Hydration Mix',
                    'onlineStoreUrl' => 'https://biosteel.ca/products/hoodie',
                    'variants' => ['nodes' => [
                        ['id' => 'gid://shopify/ProductVariant/1', 'inventoryPolicy' => 'DENY', 'inventoryItem' => ['tracked' => true]],
                    ]],
                ]],
            ]]]),
        ]);

        $this->artisan('stockout:derive-intervals')->assertExitCode(0);

        $this->assertDatabaseHas('variant_availability_intervals', [
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'source' => 'reconstructed',
        ]);
    }

    public function test_a_shopify_error_exits_with_failure(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response(['errors' => [['message' => 'boom']]])]);

        $this->artisan('stockout:derive-intervals')->assertExitCode(1);
    }
}
