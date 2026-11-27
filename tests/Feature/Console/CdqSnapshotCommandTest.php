<?php

namespace Tests\Feature\Console;

use App\Models\CdqListingSnapshot;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CdqSnapshotCommandTest extends TestCase
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
            'services.sp_api.vendor_code' => 'Z9999',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
        ]);

        Product::create(['asin' => 'B1TEST', 'sku' => 'SKU-1', 'type' => 'DRINK_FLAVORED', 'title' => 'Test', 'active' => true]);
    }

    /** @return array<string, mixed> */
    protected function baseFakes(array $attributes = ['brand' => [['value' => 'Test Brand']]], array $issues = []): array
    {
        return [
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/aplus/2020-11-01/contentDocuments?*' => Http::response(['contentMetadataRecords' => [], 'nextPageToken' => null]),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response([
                'attributes' => $attributes,
                'issues' => $issues,
            ]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([
                'images' => [['images' => [['variant' => 'MAIN', 'link' => 'https://img.test/main.jpg']]]],
                'relationships' => [['relationships' => [['parentAsins' => ['PARENT1'], 'type' => 'VARIATION']]]],
                'productTypes' => [['productType' => 'DRINK_FLAVORED']],
            ]),
            'img.test/*' => Http::response('fake-image-bytes'),
        ];
    }

    public function test_snapshot_captures_core_fields_correctly(): void
    {
        Http::fake($this->baseFakes());

        $this->artisan('cdq:snapshot')->assertSuccessful();

        $row = CdqListingSnapshot::where('asin', 'B1TEST')->firstOrFail();
        $this->assertSame('SKU-1', $row->sku);
        $this->assertSame('Z9999', $row->vendor_code);
        $this->assertSame('PARENT1', $row->parent_asin);
        $this->assertSame('DRINK_FLAVORED', $row->product_type);
        $this->assertNotNull($row->issues_checked_at);
        $this->assertSame('Test Brand', $row->attributes_json['brand'][0]['value']);
        $this->assertSame(hash('sha256', 'fake-image-bytes'), $row->main_image_hash);
    }

    /**
     * The actual proof attributes are sourced from Listings Items, not
     * Catalog Items: the fixture's Catalog Items response never has an
     * attributes key at all, so this only passes if changing ONLY the
     * Listings Items attributes changes content_hash — see the next test.
     */
    public function test_re_running_with_unchanged_content_is_deduped_by_content_hash(): void
    {
        Http::fake($this->baseFakes());

        $this->artisan('cdq:snapshot')->assertSuccessful();
        $this->artisan('cdq:snapshot')->assertSuccessful();

        $this->assertSame(1, CdqListingSnapshot::where('asin', 'B1TEST')->count());
    }

    public function test_a_real_content_change_inserts_a_new_row_preserving_history(): void
    {
        // A single Http::fake() call for the whole test, with a two-response
        // sequence on the one endpoint that changes between runs — calling
        // Http::fake() a second time later in the same test does NOT
        // override an already-registered overlapping pattern (confirmed:
        // the first-registered stub keeps matching), so this is the correct
        // way to simulate "the same endpoint returns different content on a
        // later call" within one test.
        $fakes = $this->baseFakes();
        $fakes['sp-api.test/listings/2021-08-01/items/*'] = Http::sequence()
            ->push(['attributes' => ['brand' => [['value' => 'Old Brand']]], 'issues' => []])
            ->push(['attributes' => ['brand' => [['value' => 'New Brand']]], 'issues' => []]);
        Http::fake($fakes);

        $this->artisan('cdq:snapshot')->assertSuccessful();
        $this->artisan('cdq:snapshot')->assertSuccessful();

        $this->assertSame(2, CdqListingSnapshot::where('asin', 'B1TEST')->count());
    }

    /**
     * Grade U's whole premise depends on this: a snapshot that couldn't
     * verify issues must be excluded from grade counts later, not scored
     * as if a clean check happened. issues_checked_at null is the flag
     * Phase 3 will read.
     */
    public function test_issues_checked_at_stays_null_when_listings_items_fails(): void
    {
        $fakes = $this->baseFakes();
        $fakes['sp-api.test/listings/2021-08-01/items/*'] = Http::response(['errors' => [['code' => 'Unauthorized']]], 403);
        Http::fake($fakes);

        $this->artisan('cdq:snapshot')->assertSuccessful();

        $row = CdqListingSnapshot::where('asin', 'B1TEST')->firstOrFail();
        $this->assertNull($row->issues_checked_at);
        $this->assertNull($row->issues_json);
    }

    public function test_aplus_module_detail_failure_degrades_gracefully_not_a_crash(): void
    {
        $fakes = $this->baseFakes();
        $fakes['sp-api.test/aplus/2020-11-01/contentDocuments?*'] = Http::response([
            'contentMetadataRecords' => [
                ['contentReferenceKey' => 'key1', 'contentMetadata' => ['status' => 'APPROVED', 'name' => 'Brand Story']],
            ],
            'nextPageToken' => null,
        ]);
        $fakes['sp-api.test/aplus/2020-11-01/contentDocuments/key1/asins?*'] = Http::response([
            'asinMetadataSet' => [['asin' => 'B1TEST']],
            'nextPageToken' => null,
        ]);
        // Real observed failure mode: some real content types 400 with "Unsupported content module type".
        $fakes['sp-api.test/aplus/2020-11-01/contentDocuments/key1?*'] = Http::response(
            ['errors' => [['code' => 'InvalidInput', 'details' => 'Unsupported content module type [brand-story-hero-1-image-logo].']]],
            400
        );
        Http::fake($fakes);

        $this->artisan('cdq:snapshot')->assertSuccessful();

        $row = CdqListingSnapshot::where('asin', 'B1TEST')->firstOrFail();
        $this->assertCount(1, $row->aplus_json);
        $this->assertSame('Brand Story', $row->aplus_json[0]['name']);
        $this->assertNull($row->aplus_json[0]['module_count']);
    }

    /**
     * The exact bug this guards against: Catalog Items omits productTypes
     * (falling back to products.type), and a user later corrects a bad
     * products.type value in Manage Products with no other listing content
     * changing. Since product_type wasn't in the content hash, that fix
     * used to be silently swallowed by the content_hash dedup — the stale
     * bad value stayed cached in cdq_listing_snapshot forever, and
     * cdq:sync-schemas kept 404ing against it even after the source data
     * was fixed.
     */
    public function test_a_products_type_correction_alone_inserts_a_new_snapshot_row(): void
    {
        $fakes = $this->baseFakes();
        $fakes['sp-api.test/catalog/2022-04-01/items/*'] = Http::response([
            'images' => [['images' => [['variant' => 'MAIN', 'link' => 'https://img.test/main.jpg']]]],
            'relationships' => [['relationships' => [['parentAsins' => ['PARENT1'], 'type' => 'VARIATION']]]],
            // No `productTypes` key — forces the products.type fallback, as
            // real Catalog Items responses for this app have been confirmed
            // to do for some ASINs.
        ]);
        Http::fake($fakes);

        Product::where('asin', 'B1TEST')->update(['type' => 'PROTEIN SUPPLEMENT POWDER']);
        $this->artisan('cdq:snapshot')->assertSuccessful();
        $this->assertSame('PROTEIN SUPPLEMENT POWDER', CdqListingSnapshot::where('asin', 'B1TEST')->latest('id')->value('product_type'));

        Product::where('asin', 'B1TEST')->update(['type' => 'PROTEIN_SUPPLEMENT_POWDER']);
        $this->artisan('cdq:snapshot')->assertSuccessful();

        $this->assertSame(2, CdqListingSnapshot::where('asin', 'B1TEST')->count());
        $this->assertSame('PROTEIN_SUPPLEMENT_POWDER', CdqListingSnapshot::where('asin', 'B1TEST')->latest('id')->value('product_type'));
    }

    public function test_asin_option_scopes_to_a_single_product(): void
    {
        Product::create(['asin' => 'B2OTHER', 'sku' => 'SKU-2', 'type' => 'DRINK_FLAVORED', 'title' => 'Other', 'active' => true]);
        Http::fake($this->baseFakes());

        $this->artisan('cdq:snapshot', ['--asin' => 'B1TEST'])->assertSuccessful();

        $this->assertSame(1, CdqListingSnapshot::count());
        $this->assertSame('B1TEST', CdqListingSnapshot::first()->asin);
    }
}
