<?php

namespace Tests\Unit\Services;

use App\Models\ListingAudit;
use App\Models\ListingAuditAttributeCatalog;
use App\Models\ListingAuditConfig;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Models\ScListingAudit;
use App\Models\ScProduct;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\ListingAuditService;
use App\Services\AmazonListings\ListingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'id',
            'services.sp_api.client_secret' => 'secret',
            'services.sp_api.vendor_refresh_token' => 'vendor-token',
            'services.sp_api.vendor_code' => 'Z0000',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
            'services.sp_api.seller_refresh_token' => 'seller-token',
            'services.sp_api.seller_id' => 'A0000000000000',
            'services.sp_api.seller_marketplace_id' => 'ATVPDKIKX0DER',
        ]);

        Http::fake(['api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake'])]);
    }

    protected function fakeFullSuccess(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => [
                'nutritional_info' => [['serving_quantity' => 1, 'energy' => [['content' => 100]], 'fat' => [['total' => 1]], 'carbohydrate' => [['total' => 1]], 'protein' => [['value' => 1]]]],
                'bullet_point' => [['value' => 'A'], ['value' => 'B']],
                'regulatory_compliance_certification' => [['value' => 'x']],
            ]]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['vendorDetails' => [['brandCode' => 'BIOS', 'manufacturerCode' => 'MFR1', 'replenishmentCategory' => 'REPLENISHABLE']], 'images' => [['images' => [
                // Same real bug the legacy tool documented and worked around:
                // each image variant repeats once per resolution, with a
                // different link each time — a naive count/array_unique(link)
                // triples this to 5; deduping by variant correctly gives 2.
                ['variant' => 'MAIN', 'link' => 'main-75.jpg'],
                ['variant' => 'MAIN', 'link' => 'main-500.jpg'],
                ['variant' => 'MAIN', 'link' => 'main-1920.jpg'],
                ['variant' => 'PT01', 'link' => 'pt01-75.jpg'],
                ['variant' => 'PT01', 'link' => 'pt01-500.jpg'],
            ]]]]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => [['contentType' => 'EMC']]]),
        ]);
    }

    public function test_audit_one_records_all_signals_for_vendor_channel(): void
    {
        $this->fakeFullSuccess();
        $product = Product::create(['asin' => 'B000000001', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $success = (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Vendor, $product);

        $this->assertTrue($success);
        $audit = ListingAudit::firstOrFail();
        $this->assertTrue($audit->has_serving_quantity);
        $this->assertTrue($audit->has_energy_content);
        $this->assertSame(2, $audit->bullet_point_count);
        $this->assertSame('BIOS', $audit->brand_code);
        $this->assertSame(2, $audit->image_count); // 5 raw entries, 2 distinct variants
        $this->assertSame('full', $audit->aplus_status);
        $this->assertNull($audit->fetch_error);

        // Regression guard: audited_attributes must come back as a real
        // array, not a string — a prior bug pre-encoded it with
        // json_encode() before assignment, which the array-cast column
        // then encoded *again*, leaving every real "Audit Listings" run
        // storing a JSON string of a JSON string (only ever caught because
        // most of the table's existing data came from a separately-fixed
        // import command, not this live path — see
        // listings:repair-double-encoded-audited-attributes).
        $this->assertIsArray($audit->audited_attributes);
        $this->assertArrayHasKey('bullet_point', $audit->audited_attributes);
        $this->assertSame(['kind' => 'count', 'value' => 2], $audit->audited_attributes['bullet_point']);
    }

    public function test_audit_one_records_listing_issues_from_the_same_get_listings_item_call(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response([
                'attributes' => [],
                'issues' => [[
                    'code' => '18448', 'message' => 'Missing required attribute', 'severity' => 'ERROR',
                    'attributeNames' => ['item_weight'], 'categories' => ['MISSING_ATTRIBUTE'],
                    'enforcements' => ['actions' => [['action' => 'LISTING_SUPPRESSED']], 'exemption' => ['status' => 'NONE']],
                ]],
            ]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['vendorDetails' => [], 'images' => []]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []]),
        ]);
        $product = Product::create(['asin' => 'B000000001', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Vendor, $product);

        $audit = ListingAudit::firstOrFail();
        $this->assertIsArray($audit->listing_issues);
        $this->assertCount(1, $audit->listing_issues);
        $this->assertSame('ERROR', $audit->listing_issues[0]['severity']);
        $this->assertSame('LISTING_SUPPRESSED', $audit->listing_issues[0]['enforcements']['actions'][0]['action']);
    }

    public function test_audit_one_records_listing_restrictions_when_amazon_returns_some(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['vendorDetails' => [], 'images' => []]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []]),
            'sp-api.test/listings/2021-08-01/restrictions*' => Http::response(['restrictions' => [[
                'marketplaceId' => 'A2EUQ1WTGCTBG2', 'conditionType' => 'new_new',
                'reasons' => [['message' => 'Approval required.', 'reasonCode' => 'APPROVAL_REQUIRED']],
            ]]]),
        ]);
        $product = Product::create(['asin' => 'B000000001', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Vendor, $product);

        $audit = ListingAudit::firstOrFail();
        $this->assertIsArray($audit->listing_restrictions);
        $this->assertCount(1, $audit->listing_restrictions);
        $this->assertSame('APPROVAL_REQUIRED', $audit->listing_restrictions[0]['reasons'][0]['reasonCode']);
    }

    /**
     * The core reason this call is deliberately NOT folded into the shared
     * $error/fetch_error the other 3 calls share — Amazon's docs never
     * confirm vendor support here, so a 400 is an expected, acceptable
     * outcome per the user's explicit request, not a real audit failure.
     */
    public function test_audit_one_does_not_count_a_failed_restrictions_call_as_a_fetch_error(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['vendorDetails' => [], 'images' => []]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []]),
            'sp-api.test/listings/2021-08-01/restrictions*' => Http::response(['errors' => [['message' => 'Unsupported for vendors.']]], 400),
        ]);
        $product = Product::create(['asin' => 'B000000001', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $success = (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Vendor, $product);

        $this->assertTrue($success);
        $audit = ListingAudit::firstOrFail();
        $this->assertNull($audit->fetch_error);
        $this->assertNull($audit->listing_restrictions);
    }

    public function test_audit_one_records_an_empty_listing_issues_array_when_amazon_reports_none(): void
    {
        $this->fakeFullSuccess();
        $product = Product::create(['asin' => 'B000000001', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Vendor, $product);

        $audit = ListingAudit::firstOrFail();
        $this->assertSame([], $audit->listing_issues);
    }

    public function test_audit_one_gets_image_count_but_not_vendor_details_for_seller_channel(): void
    {
        // Catalog Items is still called for the seller channel — images
        // needs it — but SpApiClient never asks for vendorDetails there
        // (it hard-400s for a seller-scoped token, see SpApiClientTest),
        // so brand/manufacturer/replenishment stay null while image_count
        // still gets populated from the same response's images.
        $this->fakeFullSuccess();
        $product = ScProduct::create(['asin' => 'B000000001', 'sku' => 'SKU-1', 'title' => 'T', 'status' => 'Active', 'synced_at' => now()]);

        (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Seller, $product);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'catalog/2022-04-01'));
        $audit = ScListingAudit::firstOrFail();
        $this->assertNull($audit->brand_code);
        $this->assertNull($audit->manufacturer_code);
        $this->assertSame(2, $audit->image_count);
    }

    public function test_seller_channel_checks_ebc_not_emc_for_aplus(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => [['contentType' => 'EBC']]]),
        ]);
        $product = ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'status' => 'Active', 'synced_at' => now()]);

        (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Seller, $product);

        $this->assertSame('full', ScListingAudit::firstOrFail()->aplus_status);
    }

    public function test_a_failed_call_is_recorded_as_a_fetch_error_and_returns_false(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response([], 500),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['vendorDetails' => [[]], 'images' => [['images' => []]]]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []]),
        ]);
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $success = (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Vendor, $product);

        $this->assertFalse($success);
        $this->assertNotNull(ListingAudit::firstOrFail()->fetch_error);
    }

    public function test_configured_slots_default_to_the_legacy_seven_when_no_config_saved(): void
    {
        $slots = (new ListingAuditService(app(SpApiClient::class)))->configuredSlots(ListingChannel::Vendor);

        $this->assertSame(ListingAuditService::DEFAULT_AUDIT_SLOTS, $slots);
    }

    public function test_configured_raw_slots_preserve_gaps_and_position_unlike_configured_slots(): void
    {
        ListingAuditAttributeCatalog::create(['attribute_key' => 'bullet_point', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        ListingAuditAttributeCatalog::create(['attribute_key' => 'regulatory_compliance_certification', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $service = new ListingAuditService(app(SpApiClient::class));

        // Slot 2 deliberately left empty, between two configured slots — the
        // exact shape that broke the config form's pre-selection, since
        // configuredSlots() compacts this down to ['bullet_point',
        // 'regulatory_compliance_certification'] with no way to tell which
        // physical slot each one came from.
        $service->updateConfig(ListingChannel::Vendor, ['bullet_point', '', 'regulatory_compliance_certification'], ['Bullets', 'Should Be Dropped', 'Reg. Compliance'], null, null);

        $rawSlots = $service->configuredRawSlots(ListingChannel::Vendor);
        $this->assertSame(['bullet_point', null, 'regulatory_compliance_certification', null, null, null, null], $rawSlots);

        $rawAliases = $service->configuredRawAliases(ListingChannel::Vendor);
        $this->assertSame(['Bullets', null, 'Reg. Compliance', null, null, null, null], $rawAliases);

        // configuredSlots() still compacts for the audit table's own use — confirming the raw variant is additive, not a behavior change.
        $this->assertSame(['bullet_point', 'regulatory_compliance_certification'], $service->configuredSlots(ListingChannel::Vendor));
    }

    public function test_update_config_rejects_unknown_attribute_keys(): void
    {
        // A real attribute only becomes selectable once an audit has
        // actually seen it — simulate that here rather than assuming
        // "bullet_point" is valid before any audit has ever run.
        ListingAuditAttributeCatalog::create(['attribute_key' => 'bullet_point', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $service = new ListingAuditService(app(SpApiClient::class));

        $service->updateConfig(ListingChannel::Vendor, ['not_a_real_attribute', 'bullet_point'], [], 'testing', null);

        $config = ListingAuditConfig::firstOrFail();
        $this->assertNull($config->attr_slot_1); // rejected — not in availableAttributes()
        $this->assertSame('bullet_point', $config->attr_slot_2);
    }

    public function test_update_config_stores_an_alias_only_for_a_slot_that_has_an_attribute(): void
    {
        ListingAuditAttributeCatalog::create(['attribute_key' => 'bullet_point', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $service = new ListingAuditService(app(SpApiClient::class));

        $service->updateConfig(ListingChannel::Vendor, ['not_a_real_attribute', 'bullet_point'], ['Should Be Dropped', 'Bullets'], 'testing', null);

        $config = ListingAuditConfig::firstOrFail();
        $this->assertNull($config->alias_slot_1); // no attribute in this slot, so no alias either
        $this->assertSame('Bullets', $config->alias_slot_2);
        $this->assertSame(['bullet_point' => 'Bullets'], $config->aliases());
    }

    public function test_audit_batch_reports_done_once_every_active_product_is_processed(): void
    {
        $this->fakeFullSuccess();
        Product::factory()->count(3)->create(['active' => true, 'type' => 'DRINK_FLAVORED']);
        $service = new ListingAuditService(app(SpApiClient::class));

        $first = $service->auditBatch(ListingChannel::Vendor, 0, 2);
        $this->assertSame(2, $first['processed']);
        $this->assertFalse($first['done']);

        $second = $service->auditBatch(ListingChannel::Vendor, $first['nextOffset'], 2);
        $this->assertSame(1, $second['processed']);
        $this->assertTrue($second['done']);
    }

    public function test_contribution_mismatch_is_null_with_no_prior_accepted_submission(): void
    {
        $this->fakeFullSuccess();
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Vendor, $product);

        $this->assertNull(ListingAudit::firstOrFail()->contribution_mismatch_count);
    }

    public function test_contribution_mismatch_detects_a_reverted_field(): void
    {
        $this->fakeFullSuccess();
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'item_name',
            'old_value' => '[]', 'new_value' => json_encode([['value' => 'Our Submitted Title']]),
            'submission_id' => 's1', 'status' => 'ACCEPTED',
        ]);

        (new ListingAuditService(app(SpApiClient::class)))->auditOne(ListingChannel::Vendor, $product);

        $audit = ListingAudit::firstOrFail();
        $this->assertSame(1, $audit->contribution_mismatch_count);
        $this->assertSame('item_name', $audit->contribution_mismatch_attrs);
    }
}
