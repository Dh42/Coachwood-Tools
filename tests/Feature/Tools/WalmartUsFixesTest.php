<?php

namespace Tests\Feature\Tools;

use App\Models\WalmartUsCatalogScore;
use App\Models\WalmartUsListingAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Found issue 11, failing on the old code first: the report download's
 * HTTP status was ignored, so Walmart's known 520 error body was read as a
 * report with no rows. The run processed 0 SKUs, skipped the Insights
 * fallback, and still wrote a catalog score (the old app's data shows it:
 * daily catalog scores, but no SKU audits since 2026-10-06).
 */
class WalmartUsFixesTest extends TestCase
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

    private function fake(int $downloadStatus, string $downloadBody): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token']),
            'marketplace.walmartapis.com/v3/items*' => Http::response(['ItemResponse' => [['sku' => 'SKU-1']], 'totalItems' => 1]),
            'marketplace.walmartapis.com/v3/reports/reportRequests?*' => Http::response(['requestId' => 'req-1']),
            'marketplace.walmartapis.com/v3/reports/reportRequests/req-1' => Http::response(['requestStatus' => 'READY']),
            'marketplace.walmartapis.com/v3/reports/reportRequests/req-1/download' => Http::response($downloadBody, $downloadStatus),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/items' => Http::response(['payload' => [
                ['sku' => 'SKU-1', 'qualityScoreData' => ['score' => 71.5, 'values' => []], 'postPurchaseIssues' => 0],
            ]]),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/score' => Http::response(['payload' => ['listingQuality' => 70]]),
        ]);
    }

    public function test_a_520_report_download_falls_back_to_the_insights_endpoints(): void
    {
        $this->fake(520, json_encode(['errors' => [['code' => 'SYSTEM_ERROR', 'description' => 'Pls try again after sometime']]]));

        $this->artisan('walmart-us:listing-audit')
            ->expectsOutputToContain('1 SKUs processed, 0 issues written, source=insights')
            ->assertSuccessful();

        $this->assertSame('71.50', WalmartUsListingAudit::where('sku', 'SKU-1')->sole()->listing_quality);
        $this->assertSame(1, WalmartUsCatalogScore::count());
    }

    public function test_a_report_with_no_rows_falls_back_too(): void
    {
        $this->fake(200, json_encode(['errors' => []]));

        $this->artisan('walmart-us:listing-audit')
            ->expectsOutputToContain('source=insights')
            ->assertSuccessful();

        $this->assertSame(1, WalmartUsListingAudit::count());
    }
}
