<?php

namespace Tests\Unit\Services;

use App\Models\WalmartUsCatalogScore;
use App\Models\WalmartUsListingAudit;
use App\Models\WalmartUsListingAuditIssue;
use App\Models\WalmartUsListingSnapshot;
use App\Services\WalmartUs\WalmartUsApiClient;
use App\Services\WalmartUs\WalmartUsListingAuditService;
use App\Services\WalmartUs\WalmartUsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalmartUsListingAuditServiceTest extends TestCase
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

    protected function makeService(): WalmartUsListingAuditService
    {
        $client = new WalmartUsApiClient;

        return new WalmartUsListingAuditService($client, new WalmartUsReportService($client));
    }

    // Real Listing Quality score shape confirmed live (2026-09-03): nested
    // under `payload` (payload.listingQuality, payload.score.*,
    // payload.postPurchaseQuality.*) — not the flat top-level fields
    // originally assumed. No item-count field anywhere in this payload.
    protected function fakeScoreResponse(float $listingQuality = 80): array
    {
        return [
            'payload' => [
                'listingQuality' => $listingQuality,
                'score' => ['contentScore' => 90, 'offerScore' => 30],
                'postPurchaseQuality' => ['itemDefectCnt' => 2, 'defectRatio' => 0.01],
            ],
            'status' => 'OK',
        ];
    }

    public function test_it_runs_the_full_report_path_and_writes_snapshot_audit_and_issue_rows(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/reports/reportRequests?*' => Http::response(['requestId' => 'req-1'], 200),
            'marketplace.walmartapis.com/v3/reports/reportRequests/req-1' => Http::response(['requestStatus' => 'READY', 'reportId' => 'rep-1']),
            'marketplace.walmartapis.com/v3/reports/reportRequests/rep-1/download' => Http::response(json_encode([
                'items' => [[
                    'sku' => 'SKU-1',
                    'wpid' => 'WPID-1',
                    'asOfDate' => '2026-09-01',
                    'listingQuality' => 82.5,
                    'contentScore' => 90,
                    'itemDefectCnt' => 1,
                    'defectRatio' => 0.05,
                    'issues' => [[
                        'code' => 'MAIN_IMAGE_MISSING',
                        'severity' => 'critical',
                        'field' => 'mainImage',
                        'message' => 'No main image present.',
                        'recommendedAction' => 'Add a main image.',
                    ]],
                ]],
            ])),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/score' => Http::response($this->fakeScoreResponse(80)),
        ]);

        $result = $this->makeService()->run(itemCount: 1);

        $this->assertSame(1, $result['skus_processed']);
        $this->assertSame(1, $result['issues_written']);
        $this->assertSame('report', $result['source']);
        $this->assertFalse($result['dry_run']);

        $this->assertDatabaseHas('walmart_us_listing_snapshot', ['sku' => 'SKU-1', 'wpid' => 'WPID-1']);
        $this->assertDatabaseHas('walmart_us_listing_audits', ['sku' => 'SKU-1', 'as_of_date' => '2026-09-01', 'listing_quality' => 82.5]);
        $this->assertDatabaseHas('walmart_us_listing_audit_issues', ['code' => 'MAIN_IMAGE_MISSING', 'source' => 'walmart']);
        $this->assertDatabaseHas('walmart_us_catalog_scores', ['listing_quality' => 80, 'item_count' => 1, 'item_defect_cnt' => 2]);

        $audit = WalmartUsListingAudit::first();
        $this->assertNull($audit->our_rule_score);
    }

    public function test_it_falls_back_to_insights_when_the_report_path_fails(): void
    {
        // Real per-item shape confirmed live (2026-09-03): sku sits
        // top-level alongside deeply nested qualityScoreData/scoreDetails —
        // nothing like the flat fixture this test used before.
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/reports/reportRequests?*' => Http::response(['error' => 'boom'], 500),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/items' => Http::response([
                'nextCursor' => 'abc',
                'totalItems' => 1,
                'payload' => [[
                    'sku' => 'SKU-2',
                    'productId' => 'PRODID-2',
                    'qualityScoreData' => [
                        'score' => 38.2,
                        'values' => [
                            ['impact' => 'HIGH', 'scoreType' => 'content & Discoverability', 'scoreValue' => 91.0],
                            ['impact' => 'HIGH', 'scoreType' => 'Shipping speed', 'scoreValue' => 0.0],
                            ['impact' => 'MEDIUM', 'scoreType' => 'Rating & Reviews', 'scoreValue' => 0.0],
                            ['impact' => 'LOW', 'scoreType' => 'Published and in stock', 'scoreValue' => 0.0],
                            ['impact' => 'ZERO', 'scoreType' => 'Price competitiveness', 'scoreValue' => null],
                            ['impact' => 'LOW', 'scoreType' => 'offer', 'scoreValue' => 0.0],
                        ],
                    ],
                    'postPurchaseIssues' => 2,
                    'scoreDetails' => [
                        'offer' => [
                            'price' => ['issueTitle' => 'Price Not Competitive'],
                            'shippingSpeed' => ['issueTitle' => null],
                        ],
                        'contentAndDiscoverability' => [
                            'issueCount' => 1,
                            'issues' => [[
                                'attributeName' => 'caffeine_designation',
                                'issueCount' => 1,
                                'issues' => [['title' => 'NOT_VALID', 'value' => 'Valid values are: A, B, C']],
                            ]],
                        ],
                        'ratingReviews' => ['maxRating' => 5, 'ratingCount' => 0],
                    ],
                ]],
            ]),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/score' => Http::response($this->fakeScoreResponse(60)),
        ]);

        $result = $this->makeService()->run(itemCount: 1);

        $this->assertSame('insights', $result['source']);
        $this->assertSame(1, $result['skus_processed']);
        $this->assertSame(2, $result['issues_written']);
        $this->assertDatabaseHas('walmart_us_listing_audits', [
            'sku' => 'SKU-2',
            'listing_quality' => 38.2,
            'content_score' => 91.0,
            'offer_score' => 0.0,
            'shipping_score' => 0.0,
            'item_defect_cnt' => 2,
        ]);
        $this->assertDatabaseHas('walmart_us_listing_audit_issues', ['code' => 'NOT_VALID', 'field' => 'caffeine_designation']);
        $this->assertDatabaseHas('walmart_us_listing_audit_issues', ['code' => 'PRICE_NOT_COMPETITIVE', 'field' => 'price']);
    }

    public function test_dry_run_writes_nothing_to_the_database(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/reports/reportRequests?*' => Http::response(['requestId' => 'req-1'], 200),
            'marketplace.walmartapis.com/v3/reports/reportRequests/req-1' => Http::response(['requestStatus' => 'READY', 'reportId' => 'rep-1']),
            'marketplace.walmartapis.com/v3/reports/reportRequests/rep-1/download' => Http::response(json_encode([
                'items' => [[
                    'sku' => 'SKU-1',
                    'asOfDate' => '2026-09-01',
                    'listingQuality' => 82.5,
                    'issues' => [['code' => 'MAIN_IMAGE_MISSING']],
                ]],
            ])),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/score' => Http::response($this->fakeScoreResponse(80)),
        ]);

        $result = $this->makeService()->run(null, true, 1);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, WalmartUsListingSnapshot::count());
        $this->assertSame(0, WalmartUsListingAudit::count());
        $this->assertSame(0, WalmartUsListingAuditIssue::count());
        $this->assertSame(0, WalmartUsCatalogScore::count());
    }

    public function test_a_sku_filter_only_processes_the_matching_sku(): void
    {
        Http::fake([
            'marketplace.walmartapis.com/v3/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 900]),
            'marketplace.walmartapis.com/v3/reports/reportRequests?*' => Http::response(['requestId' => 'req-1'], 200),
            'marketplace.walmartapis.com/v3/reports/reportRequests/req-1' => Http::response(['requestStatus' => 'READY', 'reportId' => 'rep-1']),
            'marketplace.walmartapis.com/v3/reports/reportRequests/rep-1/download' => Http::response(json_encode([
                'items' => [
                    ['sku' => 'SKU-1', 'asOfDate' => '2026-09-01', 'listingQuality' => 82.5, 'issues' => []],
                    ['sku' => 'SKU-2', 'asOfDate' => '2026-09-01', 'listingQuality' => 70, 'issues' => []],
                ],
            ])),
            'marketplace.walmartapis.com/v3/insights/items/listingQuality/score' => Http::response($this->fakeScoreResponse(80)),
        ]);

        $result = $this->makeService()->run('SKU-2', itemCount: 2);

        $this->assertSame(1, $result['skus_processed']);
        $this->assertDatabaseHas('walmart_us_listing_audits', ['sku' => 'SKU-2']);
        $this->assertDatabaseMissing('walmart_us_listing_audits', ['sku' => 'SKU-1']);
    }
}
