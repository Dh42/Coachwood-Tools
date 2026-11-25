<?php

namespace Tests\Feature\Amazon;

use App\Amazon\Probe\ProbeClient;
use App\Amazon\Probe\ProbeRunner;
use App\Console\Commands\AmazonProbeCommand;
use App\Models\AmazonCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AmazonProbeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ASIN = 'B0TESTASIN';

    private const AMOUNT = 123.45;

    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 12:00:00');
        Storage::fake('local');
        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'test-client-id',
            'services.sp_api.client_secret' => 'test-client-secret',
            'services.sp_api.vendor_refresh_token' => 'vendor-refresh-token',
            'services.sp_api.seller_refresh_token' => 'seller-refresh-token',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
        ]);

        // No real waiting in tests.
        $this->app->bind(ProbeRunner::class, fn ($app, $params) => new ProbeRunner($app->make(ProbeClient::class), fn () => null, $params['log'] ?? null));

        Http::fake(fn (Request $request) => $this->amazon($request));
    }

    public function test_it_records_every_capability_and_writes_a_summary_without_confidential_values(): void
    {
        $this->artisan('amazon:probe', ['--asin' => self::ASIN])->expectsConfirmation('Continue?', 'yes')->assertSuccessful();

        $this->assertSame(25, AmazonCapability::count());
        $status = AmazonCapability::pluck('status', 'key');

        $this->assertSame('pass', $status['report.inventory_manufacturing']);
        $this->assertSame('empty', $status['report.real_time_traffic']);       // CANCELLED
        $this->assertSame('fail', $status['report.coupons']);                  // FATAL
        $this->assertSame('role_missing', $status['report.brand_market_basket']);
        $this->assertSame('pass', $status['report.brand_search_terms']);       // ready, not downloaded
        $this->assertSame('partial', $status['data_kiosk.product_availability']);
        $this->assertSame('empty', $status['data_kiosk.history_2023']);        // DONE, no document
        $this->assertSame('pass', $status['api.finance_remittance_detail']);   // used the captured payment id
        $this->assertSame('pass', $status['api.seller_offers_ca']);

        $availability = AmazonCapability::firstWhere('key', 'data_kiosk.product_availability');
        $this->assertSame(['ultraFastTrack'], $availability->fields_rejected);
        $this->assertSame([0.12, 0.12], $availability->field_stats['productAvailability.sourceableROOS']['range']);
        $this->assertTrue(AmazonCapability::fieldReturned('data_kiosk.product_availability', 'productAvailability.sourceableROOS'));

        $codes = AmazonCapability::firstWhere('key', 'data_kiosk.vendor_codes');
        $this->assertSame(['G0002' => 1, 'Z0001' => 1], $codes->field_stats['groupByKey.vendorCode']['values']);

        $inventory = AmazonCapability::firstWhere('key', 'report.inventory_manufacturing');
        $this->assertContains('unhealthyInventoryUnits', $inventory->notes['expected_fields_never_filled']);
        $this->assertNotContains('openPurchaseOrderUnits', $inventory->notes['expected_fields_never_filled']);

        $this->assertSame('Report failed for ASIN [masked]', AmazonCapability::firstWhere('key', 'report.coupons')->error_message);
        $this->assertSame(['A2EUQ1WTGCTBG2' => 1, 'ATVPDKIKX0DER' => 1], AmazonCapability::firstWhere('key', 'api.seller_marketplaces')->field_stats['marketplace.id']['values']);

        $summary = Storage::disk('local')->get(AmazonProbeCommand::SUMMARY_PATH);
        $this->assertNotNull($summary);
        foreach ([self::ASIN, 'B0OTHERSIN', 'A1SELLER', 'INV-1', (string) self::AMOUNT, 'fake-token', 'refresh-token', 'test-client', 'PAY-123456', 'docs.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $summary, "Summary leaked {$secret}");
        }
        $this->assertTrue(Storage::disk('local')->exists(ProbeRunner::RAW_DIR.'/report.inventory_manufacturing.json.gz'));
    }

    public function test_it_sends_nothing_but_reads_and_report_or_query_creation(): void
    {
        $this->artisan('amazon:probe')->expectsConfirmation('Continue?', 'yes')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! in_array(parse_url($r->url(), PHP_URL_PATH), [
            '/auth/o2/token', '/reports/2021-06-30/reports', '/dataKiosk/2023-11-15/queries',
        ], true));
        $this->assertSame('skipped', AmazonCapability::firstWhere('key', 'api.seller_offers_ca')->status);
    }

    public function test_only_reruns_the_named_capabilities_and_keeps_the_rest(): void
    {
        $this->artisan('amazon:probe')->expectsConfirmation('Continue?', 'yes')->assertSuccessful();
        AmazonCapability::query()->where('key', 'report.traffic')->update(['status' => 'fail']);

        $this->artisan('amazon:probe', ['--only' => ['report.traffic']])->expectsConfirmation('Continue?', 'yes')->assertSuccessful();

        $this->assertSame('pass', AmazonCapability::firstWhere('key', 'report.traffic')->status);
        $this->assertSame(25, AmazonCapability::count());
    }

    public function test_it_stops_when_vendor_credentials_are_missing(): void
    {
        config(['services.sp_api.vendor_refresh_token' => null]);

        $this->artisan('amazon:probe')
            ->expectsOutputToContain('SP_API_VENDOR_REFRESH_TOKEN')
            ->assertFailed();

        Http::assertNothingSent();
    }

    /** A small fake of the SP-API endpoints the probe calls. */
    private function amazon(Request $request)
    {
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);
        $method = $request->method();

        if (str_contains($url, 'api.amazon.com/auth/o2/token')) {
            return Http::response(['access_token' => 'fake-token']);
        }

        // Report documents and query documents.
        if (str_starts_with($url, 'https://docs.test/report/')) {
            $type = basename($path);
            if ($type === 'GET_COUPON_PERFORMANCE_REPORT') {
                return Http::response(gzencode(json_encode(['errorDetails' => 'Report failed for ASIN '.self::ASIN])));
            }

            return Http::response(gzencode(json_encode([
                'reportSpecification' => ['reportType' => $type],
                'inventoryAggregate' => [['openPurchaseOrderUnits' => 50]],
                'inventoryByAsin' => [
                    ['asin' => self::ASIN, 'openPurchaseOrderUnits' => 12, 'unhealthyInventoryUnits' => null, 'vendorConfirmationRate' => 0.93, 'sellableOnHandInventoryCost' => self::AMOUNT],
                    ['asin' => 'B0OTHERSIN', 'openPurchaseOrderUnits' => 0, 'vendorConfirmationRate' => 0.88, 'sellableOnHandInventoryCost' => 10.0],
                ],
            ])));
        }
        if (str_starts_with($url, 'https://docs.test/kiosk/')) {
            $line = fn (string $code) => json_encode(['startDate' => '2026-10-25', 'endDate' => '2026-10-31', 'metrics' => [[
                'groupByKey' => ['asin' => self::ASIN, 'vendorCode' => $code],
                'metrics' => ['productAvailability' => ['sourceableROOS' => 0.12, 'sellableOnHandInventory' => ['units' => 5, 'value' => ['amount' => self::AMOUNT, 'currencyCode' => 'CAD']]]],
            ]]]);

            return Http::response(gzencode($line('Z0001')."\n".$line('G0002')."\n"));
        }

        // Reports API.
        if ($path === '/reports/2021-06-30/reports' && $method === 'POST') {
            $type = $request->data()['reportType'];

            return $type === 'GET_BRAND_ANALYTICS_MARKET_BASKET_REPORT'
                ? Http::response(['errors' => [['code' => 'Unauthorized', 'message' => 'Access to requested resource is denied.']]], 403)
                : Http::response(['reportId' => 'rep-'.$type], 202);
        }
        if (preg_match('#^/reports/2021-06-30/reports/rep-(.+)$#', $path, $m)) {
            $status = match ($m[1]) {
                'GET_VENDOR_REAL_TIME_TRAFFIC_REPORT' => 'CANCELLED',
                'GET_COUPON_PERFORMANCE_REPORT' => 'FATAL',
                default => 'DONE',
            };

            return Http::response(['processingStatus' => $status, 'reportDocumentId' => $status === 'CANCELLED' ? null : 'doc-'.$m[1]]);
        }
        if (preg_match('#^/reports/2021-06-30/documents/doc-(.+)$#', $path, $m)) {
            return Http::response(['url' => 'https://docs.test/report/'.$m[1], 'compressionAlgorithm' => 'GZIP']);
        }

        // Data Kiosk.
        if ($path === '/dataKiosk/2023-11-15/queries' && $method === 'POST') {
            $gql = $request->data()['query'];
            if (str_contains($gql, 'ultraFastTrack')) {
                return Http::response(['errors' => [['code' => 'InvalidInput', 'message' => "Validation error of type FieldUndefined: Field 'ultraFastTrack' in type 'ProductAvailability' is undefined @ 'x'"]]], 400);
            }
            $this->queries[] = $gql;

            return Http::response(['queryId' => 'q-'.(count($this->queries) - 1)], 202);
        }
        if (preg_match('#^/dataKiosk/2023-11-15/queries/q-(\d+)$#', $path, $m)) {
            $isHistory = str_contains($this->queries[(int) $m[1]], '2023-01-01');

            return Http::response(array_filter(['processingStatus' => 'DONE', 'dataDocumentId' => $isHistory ? null : 'dk-'.$m[1]]));
        }
        if (preg_match('#^/dataKiosk/2023-11-15/documents/(dk-\d+)$#', $path, $m)) {
            return Http::response(['documentUrl' => 'https://docs.test/kiosk/'.$m[1]]);
        }

        // Synchronous APIs.
        return match (true) {
            $path === '/finances/remittances/2026-03-17/remittances' => Http::response(['remittances' => [['uniquePaymentId' => 'PAY-123456', 'paymentAmount' => ['amount' => self::AMOUNT, 'currencyCode' => 'CAD']]]]),
            $path === '/finances/remittances/2026-03-17/remittances/PAY-123456' => Http::response(['lineItems' => [['invoiceNumber' => 'INV-1', 'amount' => ['amount' => self::AMOUNT, 'currencyCode' => 'CAD']]]]),
            $path === '/vendor/orders/v1/purchaseOrders' => Http::response(['payload' => ['orders' => [
                ['purchaseOrderNumber' => 'PO1', 'orderDetails' => ['sellingParty' => ['partyId' => 'Z0001']]],
                ['purchaseOrderNumber' => 'PO2', 'orderDetails' => ['sellingParty' => ['partyId' => 'G0002']]],
            ]]]),
            $path === '/sellers/v1/marketplaceParticipations' => Http::response(['payload' => [
                ['marketplace' => ['id' => 'ATVPDKIKX0DER', 'countryCode' => 'US'], 'participation' => ['isParticipating' => true]],
                ['marketplace' => ['id' => 'A2EUQ1WTGCTBG2', 'countryCode' => 'CA'], 'participation' => ['isParticipating' => true]],
            ]]),
            $path === '/products/pricing/v0/items/'.self::ASIN.'/offers' => Http::response(['payload' => ['ASIN' => self::ASIN, 'Offers' => [
                ['SellerId' => 'A1SELLER', 'ListingPrice' => ['Amount' => self::AMOUNT, 'CurrencyCode' => 'CAD'], 'IsBuyBoxWinner' => true],
            ]]]),
            default => Http::response(['errors' => [['code' => 'NotFound', 'message' => "Unexpected call {$method} {$path}"]]], 404),
        };
    }
}
