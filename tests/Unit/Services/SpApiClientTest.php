<?php

namespace Tests\Unit\Services;

use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\ListingChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SpApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'test-client-id',
            'services.sp_api.client_secret' => 'test-client-secret',
            'services.sp_api.vendor_refresh_token' => 'vendor-refresh-token',
            'services.sp_api.vendor_code' => 'Z0000',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
            'services.sp_api.seller_refresh_token' => 'seller-refresh-token',
            'services.sp_api.seller_id' => 'A0000000000000',
            'services.sp_api.seller_marketplace_id' => 'ATVPDKIKX0DER',
        ]);
    }

    protected function fakeToken(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
        ]);
    }

    public function test_access_token_is_cached_across_multiple_requests(): void
    {
        $this->fakeToken();
        Http::fake([
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['summaries' => []]),
        ]);

        $client = new SpApiClient;
        $client->getCatalogItem('B000000001', ListingChannel::Vendor);
        $client->getCatalogItem('B000000002', ListingChannel::Vendor);

        Http::assertSentCount(3); // 1 token exchange + 2 catalog calls, not 2 token exchanges + 2 calls
    }

    public function test_token_exchange_failure_throws(): void
    {
        Http::fake(['api.amazon.com/auth/o2/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(RuntimeException::class);

        (new SpApiClient)->getCatalogItem('B000000001', ListingChannel::Vendor);
    }

    public function test_get_catalog_item_includes_vendor_details_for_vendor_channel_only(): void
    {
        $this->fakeToken();
        Http::fake(['sp-api.test/catalog/2022-04-01/items/*' => Http::response(['summaries' => []])]);

        (new SpApiClient)->getCatalogItem('B000000001', ListingChannel::Vendor);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'vendorDetails'));

        Cache::flush();
        $this->fakeToken();
        Http::fake(['sp-api.test/catalog/2022-04-01/items/*' => Http::response(['summaries' => []])]);
        (new SpApiClient)->getCatalogItem('B000000001', ListingChannel::Seller);
        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'vendorDetails'));
    }

    public function test_get_listings_item_uses_the_correct_identity_path_segment_per_channel(): void
    {
        $this->fakeToken();
        Http::fake(['sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []])]);

        (new SpApiClient)->getListingsItem('SKU-1', ListingChannel::Vendor);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/items/Z0000/SKU-1'));

        (new SpApiClient)->getListingsItem('SKU-1', ListingChannel::Seller);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/items/A0000000000000/SKU-1'));
    }

    public function test_patch_listing_sends_validation_preview_mode_when_previewing(): void
    {
        $this->fakeToken();
        Http::fake(['sp-api.test/listings/2021-08-01/items/*' => Http::response(['status' => 'VALID'])]);

        (new SpApiClient)->patchListing('SKU-1', 'DRINK_FLAVORED', [['op' => 'replace']], true, ListingChannel::Vendor);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'mode=VALIDATION_PREVIEW'));
    }

    public function test_patch_listing_omits_preview_mode_for_a_real_submission(): void
    {
        // A real PATCH needs writes on since step 11 (plan section 2, rule 3);
        // ListingsEditorFixesTest covers it refusing with writes off.
        config(['tools.external_writes' => true]);
        $this->fakeToken();
        Http::fake(['sp-api.test/listings/2021-08-01/items/*' => Http::response(['status' => 'ACCEPTED'])]);

        (new SpApiClient)->patchListing('SKU-1', 'DRINK_FLAVORED', [['op' => 'replace']], false, ListingChannel::Vendor);

        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'VALIDATION_PREVIEW'));
    }

    public function test_get_aplus_publish_records_uses_singular_marketplace_id_param(): void
    {
        $this->fakeToken();
        Http::fake(['sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []])]);

        (new SpApiClient)->getAplusPublishRecords('B000000001', ListingChannel::Vendor);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'marketplaceId=A2EUQ1WTGCTBG2') && ! str_contains($r->url(), 'marketplaceIds='));
    }

    public function test_wait_for_report_polls_until_done(): void
    {
        $this->fakeToken();
        Http::fake([
            'sp-api.test/reports/2021-06-30/reports/report-1' => Http::sequence()
                ->push(['processingStatus' => 'IN_PROGRESS'])
                ->push(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1']),
        ]);

        $result = (new SpApiClient)->waitForReport('report-1', ListingChannel::Seller, maxWaitSeconds: 30, pollIntervalSeconds: 0);

        $this->assertSame('DONE', $result['body']['processingStatus']);
    }

    public function test_wait_for_report_throws_after_max_wait(): void
    {
        $this->fakeToken();
        Http::fake([
            'sp-api.test/reports/2021-06-30/reports/report-1' => Http::response(['processingStatus' => 'IN_PROGRESS']),
        ]);

        $this->expectException(RuntimeException::class);

        (new SpApiClient)->waitForReport('report-1', ListingChannel::Seller, maxWaitSeconds: 0, pollIntervalSeconds: 0);
    }

    public function test_get_purchase_orders_hits_the_vendor_orders_endpoint_with_given_query(): void
    {
        $this->fakeToken();
        Http::fake(['sp-api.test/vendor/orders/v1/purchaseOrders*' => Http::response(['payload' => ['orders' => []]])]);

        (new SpApiClient)->getPurchaseOrders(['createdAfter' => '2026-08-01T00:00:00Z', 'purchaseOrderState' => 'New', 'limit' => 100], ListingChannel::Vendor);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/vendor/orders/v1/purchaseOrders')
            && str_contains($r->url(), 'purchaseOrderState=New')
            && str_contains($r->url(), 'limit=100'));
    }

    public function test_get_listings_restrictions_hits_the_restrictions_endpoint_with_vendor_identity(): void
    {
        $this->fakeToken();
        Http::fake(['sp-api.test/listings/2021-08-01/restrictions*' => Http::response(['restrictions' => []])]);

        (new SpApiClient)->getListingsRestrictions('B000000001', ListingChannel::Vendor);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/listings/2021-08-01/restrictions')
            && str_contains($r->url(), 'asin=B000000001')
            && str_contains($r->url(), 'sellerId=Z0000')
            && str_contains($r->url(), 'marketplaceIds=A2EUQ1WTGCTBG2'));
    }

    public function test_get_replenishment_offer_metrics_posts_the_expected_filters(): void
    {
        $this->fakeToken();
        Http::fake(['sp-api.test/replenishment/2022-11-07/offers/metrics/search' => Http::response(['offers' => []])]);

        (new SpApiClient)->getReplenishmentOfferMetrics(['B000000001', 'B000000002'], '2026-08-17', '2026-08-17', ListingChannel::Vendor);

        Http::assertSent(function ($r) {
            $body = $r->data();

            return str_contains($r->url(), '/replenishment/2022-11-07/offers/metrics/search')
                && $body['filters']['timePeriodType'] === 'PERFORMANCE'
                && $body['filters']['programTypes'] === ['SUBSCRIBE_AND_SAVE']
                && $body['filters']['marketplaceId'] === 'A2EUQ1WTGCTBG2'
                && $body['filters']['asins'] === ['B000000001', 'B000000002']
                && $body['filters']['timeInterval']['startDate'] === '2026-08-17'
                && $body['pagination']['limit'] === 20;
        });
    }
}
