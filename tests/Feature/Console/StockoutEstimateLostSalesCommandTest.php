<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockoutEstimateLostSalesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stockout.shopify.store' => 'shopify-test.myshopify.com',
            'stockout.shopify.access_token' => 'test-token',
            'stockout.api_version' => '2026-07',
        ]);

        // RtdPetSuccessorResolver's live query - no RTD/PET pairs in this test's fixtures.
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response(['data' => ['productVariants' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => ''],
                'nodes' => [],
            ]]]),
        ]);
    }

    public function test_it_writes_estimates_from_seeded_intervals_and_sales(): void
    {
        DB::table('sales_history_daily')->insert([
            'product_title' => 'BIOSTEEL HOODIE',
            'day' => '2026-01-09',
            'net_items_sold' => 10,
            'net_sales' => 100,
            'ingested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('variant_availability_intervals')->insert([
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'product_title' => 'BIOSTEEL HOODIE',
            'started_at' => '2026-01-10 00:00:00',
            'ended_at' => '2026-01-10 23:59:59',
            'source' => 'reconstructed',
            'confidence' => 'day_level_single_variant',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('stockout:estimate-lost-sales')->assertExitCode(0);

        $this->assertDatabaseHas('lost_sales_estimates', ['variant_id' => 'gid://shopify/ProductVariant/1']);
    }
}
