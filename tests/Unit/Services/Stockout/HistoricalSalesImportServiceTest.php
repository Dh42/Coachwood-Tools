<?php

namespace Tests\Unit\Services\Stockout;

use App\Services\Stockout\HistoricalSalesImportService;
use App\Services\Stockout\ShopifyGraphQlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HistoricalSalesImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stockout.shopify.store' => 'shopify-test.myshopify.com',
            'stockout.shopify.access_token' => 'test-token',
            'stockout.api_version' => '2026-07',
            'stockout.excluded_product_titles' => ['RTD Sample Box'],
        ]);
    }

    private function salesResponse(array $rows, array $parseErrors = []): array
    {
        return ['data' => ['shopifyqlQuery' => ['tableData' => ['rows' => $rows], 'parseErrors' => $parseErrors]]];
    }

    public function test_it_chunks_a_multi_day_range_into_five_day_windows(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push($this->salesResponse([]))
                ->push($this->salesResponse([])),
        ]);

        (new HistoricalSalesImportService(app(ShopifyGraphQlClient::class)))
            ->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-10'));

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r['query'], 'SINCE 2026-01-01 UNTIL 2026-01-05'));
        Http::assertSent(fn ($r) => str_contains($r['query'], 'SINCE 2026-01-06 UNTIL 2026-01-10'));
    }

    public function test_it_writes_real_rows_and_skips_placeholder_and_excluded_rows(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response($this->salesResponse([
                ['day' => '2026-01-01', 'product_title' => 'BIOSTEEL HOODIE', 'net_items_sold' => 3, 'net_sales' => 59.97],
                ['day' => '2026-01-01', 'product_title' => '', 'net_items_sold' => 0, 'net_sales' => 0],
                ['day' => '2026-01-01', 'product_title' => 'RTD Sample Box', 'net_items_sold' => 1, 'net_sales' => 5],
            ])),
        ]);

        $result = (new HistoricalSalesImportService(app(ShopifyGraphQlClient::class)))
            ->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));

        $this->assertSame(1, $result['rows_written']);
        $this->assertSame(1, $result['rows_excluded']);
        $this->assertDatabaseHas('sales_history_daily', [
            'product_title' => 'BIOSTEEL HOODIE',
            'day' => '2026-01-01',
            'net_items_sold' => 3,
            'net_sales' => 59.97,
        ]);
    }

    public function test_a_re_run_upserts_rather_than_duplicates(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push($this->salesResponse([['day' => '2026-01-01', 'product_title' => 'BIOSTEEL HOODIE', 'net_items_sold' => 3, 'net_sales' => 59.97]]))
                ->push($this->salesResponse([['day' => '2026-01-01', 'product_title' => 'BIOSTEEL HOODIE', 'net_items_sold' => 5, 'net_sales' => 99.95]])),
        ]);

        $service = new HistoricalSalesImportService(app(ShopifyGraphQlClient::class));
        $service->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));
        $service->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));

        $this->assertDatabaseCount('sales_history_daily', 1);
        $this->assertDatabaseHas('sales_history_daily', ['net_items_sold' => 5, 'net_sales' => 99.95]);
    }

    public function test_a_shopify_error_throws(): void
    {
        Http::fake(['shopify-test.myshopify.com/*' => Http::response(['errors' => [['message' => 'boom']]])]);

        $this->expectException(RuntimeException::class);

        (new HistoricalSalesImportService(app(ShopifyGraphQlClient::class)))
            ->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));
    }
}
