<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockoutImportShopifyqlHistoryCommandTest extends TestCase
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
        ]);
    }

    public function test_it_imports_a_given_since_until_range(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response([
                'data' => ['shopifyqlQuery' => ['tableData' => ['rows' => [
                    ['day' => '2026-01-01', 'product_title' => 'BIOSTEEL HOODIE', 'ending_inventory_units' => -5],
                ]], 'parseErrors' => []]],
            ]),
        ]);

        $this->artisan('stockout:import-shopifyql-history', ['--since' => '2026-01-01', '--until' => '2026-01-01'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('inventory_history_daily', ['product_title' => 'BIOSTEEL HOODIE', 'day' => '2026-01-01']);
    }

    public function test_since_after_until_fails_without_calling_shopify(): void
    {
        Http::fake();

        $this->artisan('stockout:import-shopifyql-history', ['--since' => '2026-01-05', '--until' => '2026-01-01'])
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_shopify_failure_exits_with_an_error(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response(['errors' => [['message' => 'boom']]]),
        ]);

        $this->artisan('stockout:import-shopifyql-history', ['--since' => '2026-01-01', '--until' => '2026-01-01'])
            ->assertExitCode(1);
    }
}
