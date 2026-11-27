<?php

namespace Tests\Feature\Amazon;

use App\Amazon\Backfill\HistoryGrabRunner;
use App\Amazon\Probe\ProbeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AmazonHistoryGrabCommandTest extends TestCase
{
    private const ASIN = 'B0TESTASIN';

    private const AMOUNT = 123.45;

    /** @var list<string> */
    private array $queries = [];

    /** @var array<string, int> status checks per query id */
    private array $polls = [];

    private bool $rateLimitNextSubmit = false;

    /** @var list<string> query ids that end FATAL */
    private array $fatal = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-27 15:00:00');
        Storage::fake('local');
        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'test-client-id',
            'services.sp_api.client_secret' => 'test-client-secret',
            'services.sp_api.vendor_refresh_token' => 'vendor-refresh-token',
        ]);

        $this->app->bind(HistoryGrabRunner::class, fn ($app, $params) => new HistoryGrabRunner($app->make(ProbeClient::class), fn () => null, $params['log'] ?? null));

        Http::fake(fn (Request $request) => $this->amazon($request));
    }

    public function test_it_saves_every_query_and_a_manifest_without_confidential_values(): void
    {
        $this->rateLimitNextSubmit = true;

        $this->artisan('amazon:history-grab')->expectsConfirmation('Continue?', 'yes')->assertSuccessful();

        $manifest = HistoryGrabRunner::manifest();
        $this->assertCount(9, $manifest);
        foreach ($manifest as $key => $entry) {
            $this->assertSame('saved', $entry['status'], $key);
            $this->assertTrue(Storage::disk('local')->exists(HistoryGrabRunner::path($key)), $key);
        }

        // The rejected field was dropped and the rest still fetched.
        $this->assertSame(['lostFeaturedOffer'], $manifest['weekly.traffic']['fields_rejected']);
        $this->assertStringNotContainsString('lostFeaturedOffer', $manifest['weekly.traffic']['query']);

        $this->assertSame(['periods' => 2, 'rows' => 2, 'period_range' => ['2024-12-01', '2024-12-14']], array_intersect_key($manifest['weekly.sales_costs'], array_flip(['periods', 'rows', 'period_range'])));

        // The raw file is Amazon's document, unchanged.
        $raw = gzdecode(Storage::disk('local')->get(HistoryGrabRunner::path('weekly.sales_costs')));
        $this->assertStringContainsString(self::ASIN, $raw);

        $json = Storage::disk('local')->get(HistoryGrabRunner::MANIFEST);
        foreach ([self::ASIN, (string) self::AMOUNT, 'fake-token', 'refresh-token', 'test-client', 'docs.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "Manifest leaked {$secret}");
        }
    }

    public function test_it_sends_nothing_but_reads_and_query_creation(): void
    {
        $this->artisan('amazon:history-grab')->expectsConfirmation('Continue?', 'yes')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! in_array(parse_url($r->url(), PHP_URL_PATH), [
            '/auth/o2/token', '/dataKiosk/2023-11-15/queries',
        ], true));
    }

    public function test_a_rerun_skips_saved_queries_and_retries_failed_ones(): void
    {
        $this->fatal = ['q-2'];
        $this->artisan('amazon:history-grab')->expectsConfirmation('Continue?', 'yes')->assertFailed();

        $manifest = HistoryGrabRunner::manifest();
        $failedKey = array_keys($manifest)[2];
        $this->assertSame('fail', $manifest[$failedKey]['status']);
        $this->assertSame('Exception while fetching data (/analytics_vendorAnalytics_2024_09_30/manufacturingView) : Weekly Inventory data is only available for 8 weeks, including the current week. [asin]', $manifest[$failedKey]['error']);
        $this->assertCount(9, $this->queries);

        $this->fatal = [];
        $this->artisan('amazon:history-grab')->expectsConfirmation('Continue?', 'yes')->assertSuccessful();

        $this->assertCount(10, $this->queries, 'Only the failed query is submitted again.');
        $this->assertSame('saved', HistoryGrabRunner::manifest()[$failedKey]['status']);
    }

    public function test_list_calls_nothing(): void
    {
        $this->artisan('amazon:history-grab', ['--list' => true])
            ->expectsOutputToContain('2024-11-28')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_stops_once_the_window_has_passed(): void
    {
        Carbon::setTestNow('2027-01-05 12:00:00');

        $this->artisan('amazon:history-grab')->expectsOutputToContain('Nothing left to fetch')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_stops_when_vendor_credentials_are_missing(): void
    {
        config(['services.sp_api.vendor_refresh_token' => null]);

        $this->artisan('amazon:history-grab')->expectsOutputToContain('SP_API_VENDOR_REFRESH_TOKEN')->assertFailed();

        Http::assertNothingSent();
    }

    /** A small fake of the Data Kiosk endpoints. */
    private function amazon(Request $request)
    {
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);

        if (str_contains($url, 'api.amazon.com/auth/o2/token')) {
            return Http::response(['access_token' => 'fake-token']);
        }

        if (str_starts_with($url, 'https://docs.test/error/')) {
            // Amazon's error document is a bare GraphQL errors array (seen live 2026-11-26).
            return Http::response(json_encode([[
                'message' => 'Exception while fetching data (/analytics_vendorAnalytics_2024_09_30/manufacturingView) : Weekly Inventory data is only available for 8 weeks, including the current week. '.self::ASIN,
                'locations' => [['line' => 1, 'column' => 46]],
                'path' => ['analytics_vendorAnalytics_2024_09_30', 'manufacturingView'],
            ]]));
        }
        if (str_starts_with($url, 'https://docs.test/kiosk/')) {
            $line = fn (string $start, string $end) => json_encode(['startDate' => $start, 'endDate' => $end, 'metrics' => [[
                'groupByKey' => ['asin' => self::ASIN],
                'metrics' => ['shippedOrders' => ['shippedUnitsWithRevenue' => ['units' => 5, 'value' => ['amount' => self::AMOUNT, 'currencyCode' => 'CAD']]]],
            ]]]);

            return Http::response(gzencode($line('2024-12-01', '2024-12-07')."\n".$line('2024-12-08', '2024-12-14')."\n"));
        }

        if ($path === '/dataKiosk/2023-11-15/queries' && $request->method() === 'POST') {
            if ($this->rateLimitNextSubmit) {
                $this->rateLimitNextSubmit = false;

                return Http::response(['errors' => [['code' => 'QuotaExceeded', 'message' => 'You exceeded your quota for the requested resource.']]], 429);
            }
            $gql = $request->data()['query'];
            if (str_contains($gql, 'lostFeaturedOffer')) {
                return Http::response(['errors' => [['code' => 'InvalidInput', 'message' => "Validation error of type FieldUndefined: Field 'lostFeaturedOffer' in type 'Traffic' is undefined @ 'x'"]]], 400);
            }
            $this->queries[] = $gql;

            return Http::response(['queryId' => 'q-'.(count($this->queries) - 1)], 202);
        }
        if (preg_match('#^/dataKiosk/2023-11-15/queries/(q-\d+)$#', $path, $m)) {
            $this->polls[$m[1]] = ($this->polls[$m[1]] ?? 0) + 1;
            if ($this->polls[$m[1]] === 1) {
                return Http::response(['processingStatus' => 'IN_PROGRESS']);
            }

            return in_array($m[1], $this->fatal, true)
                ? Http::response(['processingStatus' => 'FATAL', 'errorDocumentId' => 'err-'.$m[1]])
                : Http::response(['processingStatus' => 'DONE', 'dataDocumentId' => 'dk-'.$m[1]]);
        }
        if (preg_match('#^/dataKiosk/2023-11-15/documents/(dk|err)-(q-\d+)$#', $path, $m)) {
            return Http::response(['documentUrl' => 'https://docs.test/'.($m[1] === 'err' ? 'error' : 'kiosk').'/'.$m[2]]);
        }

        return Http::response(['errors' => [['code' => 'NotFound', 'message' => "Unexpected call {$request->method()} {$path}"]]], 404);
    }
}
