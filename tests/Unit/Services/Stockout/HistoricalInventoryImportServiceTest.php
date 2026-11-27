<?php

namespace Tests\Unit\Services\Stockout;

use App\Services\Stockout\HistoricalInventoryImportService;
use App\Services\Stockout\ShopifyGraphQlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HistoricalInventoryImportServiceTest extends TestCase
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

    private function shopifyqlResponse(array $rows, array $parseErrors = []): array
    {
        return ['data' => ['shopifyqlQuery' => ['tableData' => ['rows' => $rows], 'parseErrors' => $parseErrors]]];
    }

    public function test_it_chunks_a_multi_day_range_into_two_day_windows(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push($this->shopifyqlResponse([]))
                ->push($this->shopifyqlResponse([])),
        ]);

        (new HistoricalInventoryImportService(app(ShopifyGraphQlClient::class)))
            ->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-04'));

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r['query'], 'SINCE 2026-01-01 UNTIL 2026-01-02'));
        Http::assertSent(fn ($r) => str_contains($r['query'], 'SINCE 2026-01-03 UNTIL 2026-01-04'));
    }

    public function test_it_writes_real_rows_and_skips_placeholder_and_excluded_rows(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response($this->shopifyqlResponse([
                ['day' => '2026-01-01', 'product_title' => 'BIOSTEEL HOODIE', 'ending_inventory_units' => -5],
                ['day' => '2026-01-01', 'product_title' => '', 'ending_inventory_units' => 0], // placeholder
                ['day' => '2026-01-01', 'product_title' => 'RTD Sample Box', 'ending_inventory_units' => -1], // excluded
            ])),
        ]);

        $result = (new HistoricalInventoryImportService(app(ShopifyGraphQlClient::class)))
            ->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));

        $this->assertSame(1, $result['chunks']);
        $this->assertSame(1, $result['rows_written']);
        $this->assertSame(1, $result['rows_excluded']);

        $this->assertDatabaseCount('inventory_history_daily', 1);
        $this->assertDatabaseHas('inventory_history_daily', [
            'product_title' => 'BIOSTEEL HOODIE',
            'day' => '2026-01-01',
            'ending_inventory_units' => -5,
        ]);
    }

    public function test_a_re_run_over_the_same_range_upserts_rather_than_duplicates(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push($this->shopifyqlResponse([
                    ['day' => '2026-01-01', 'product_title' => 'BIOSTEEL HOODIE', 'ending_inventory_units' => -5],
                ]))
                ->push($this->shopifyqlResponse([
                    ['day' => '2026-01-01', 'product_title' => 'BIOSTEEL HOODIE', 'ending_inventory_units' => -8],
                ])),
        ]);

        $service = new HistoricalInventoryImportService(app(ShopifyGraphQlClient::class));
        $service->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));
        $service->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));

        $this->assertDatabaseCount('inventory_history_daily', 1);
        $this->assertDatabaseHas('inventory_history_daily', [
            'product_title' => 'BIOSTEEL HOODIE',
            'day' => '2026-01-01',
            'ending_inventory_units' => -8,
        ]);
    }

    public function test_a_shopify_error_stops_the_import_without_losing_already_ingested_chunks(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push($this->shopifyqlResponse([
                    ['day' => '2026-01-01', 'product_title' => 'BIOSTEEL HOODIE', 'ending_inventory_units' => -5],
                ]))
                ->push(['errors' => [['message' => 'Something broke']]]),
        ]);

        $thrown = null;

        try {
            (new HistoricalInventoryImportService(app(ShopifyGraphQlClient::class)))
                ->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-04'));
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertDatabaseCount('inventory_history_daily', 1);
        $this->assertDatabaseHas('inventory_history_daily', ['product_title' => 'BIOSTEEL HOODIE', 'day' => '2026-01-01']);
    }

    public function test_a_parse_error_throws(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response($this->shopifyqlResponse([], ['Unexpected token'])),
        ]);

        $this->expectException(RuntimeException::class);

        (new HistoricalInventoryImportService(app(ShopifyGraphQlClient::class)))
            ->import(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));
    }
}
