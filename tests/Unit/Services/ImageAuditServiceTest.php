<?php

namespace Tests\Unit\Services;

use App\Models\ImageAudit;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\ImageAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImageAuditServiceTest extends TestCase
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
        ]);

        Http::fake(['api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake'])]);
    }

    protected function product(): Product
    {
        return Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
    }

    public function test_a_live_slot_with_no_submission_history_is_unattributed(): void
    {
        $product = $this->product();
        $liveAttributes = ['main_product_image_locator' => [['media_location' => 'https://amazon.example/main.jpg']]];

        $unattributed = (new ImageAuditService(app(SpApiClient::class)))->computeUnattributedVariants($product, $liveAttributes);

        $this->assertSame(['MAIN'], $unattributed);
    }

    public function test_a_live_slot_matching_our_last_accepted_submission_is_not_flagged(): void
    {
        $product = $this->product();
        $value = [['media_location' => 'https://tools.example/main.jpg']];
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'main_product_image_locator',
            'old_value' => '[]', 'new_value' => json_encode($value), 'status' => 'ACCEPTED',
        ]);

        $unattributed = (new ImageAuditService(app(SpApiClient::class)))->computeUnattributedVariants($product, ['main_product_image_locator' => $value]);

        $this->assertSame([], $unattributed);
    }

    public function test_a_live_slot_that_drifted_from_our_last_submission_is_not_live_not_not_ours(): void
    {
        // We DID submit something for this slot, so a mismatch is "not
        // live" (Amazon's own wording for a vendor's own submission not
        // showing), never "not ours" — that flag is reserved for slots we
        // never touched at all.
        $product = $this->product();
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'main_product_image_locator',
            'old_value' => '[]', 'new_value' => json_encode([['media_location' => 'https://tools.example/old.jpg']]), 'status' => 'ACCEPTED',
        ]);

        $service = new ImageAuditService(app(SpApiClient::class));
        $liveAttributes = ['main_product_image_locator' => [['media_location' => 'https://amazon.example/someone-elses.jpg']]];

        $this->assertSame([], $service->computeUnattributedVariants($product, $liveAttributes));
        $this->assertSame(['MAIN'], $service->computeNotLiveVariants($product, $liveAttributes));
    }

    public function test_a_submission_with_nothing_currently_live_is_not_live_not_not_ours(): void
    {
        // Amazon's own Vendor Central shows exactly this as "Not live" on a
        // vendor's own submitted-images panel — not the same as "not ours,"
        // which requires something else to actually be live.
        $product = $this->product();
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'other_product_image_locator_1',
            'old_value' => '[]', 'new_value' => json_encode([['media_location' => 'https://tools.example/pt01.jpg']]), 'status' => 'ACCEPTED',
        ]);

        $service = new ImageAuditService(app(SpApiClient::class));

        $this->assertSame([], $service->computeUnattributedVariants($product, []));
        $this->assertSame(['PT01'], $service->computeNotLiveVariants($product, []));
    }

    public function test_compute_slot_statuses_returns_our_submitted_url_alongside_the_status(): void
    {
        $product = $this->product();
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'main_product_image_locator',
            'old_value' => '[]', 'new_value' => json_encode([['media_location' => 'https://tools.example/main.jpg']]), 'status' => 'ACCEPTED',
        ]);

        $statuses = (new ImageAuditService(app(SpApiClient::class)))->computeSlotStatuses($product, [
            'main_product_image_locator' => [['media_location' => 'https://tools.example/main.jpg']],
        ]);

        $this->assertSame('live', $statuses['MAIN']['status']);
        $this->assertSame('https://tools.example/main.jpg', $statuses['MAIN']['ours_url']);
        $this->assertSame('empty', $statuses['PT01']['status']);
        $this->assertNull($statuses['PT01']['ours_url']);
    }

    public function test_only_the_most_recent_accepted_submission_per_attribute_counts(): void
    {
        $product = $this->product();
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'main_product_image_locator',
            'old_value' => '[]', 'new_value' => json_encode([['media_location' => 'https://tools.example/first.jpg']]),
            'status' => 'ACCEPTED', 'created_at' => now()->subDay(),
        ]);
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'main_product_image_locator',
            'old_value' => '[]', 'new_value' => json_encode([['media_location' => 'https://tools.example/second.jpg']]),
            'status' => 'ACCEPTED', 'created_at' => now(),
        ]);

        $unattributed = (new ImageAuditService(app(SpApiClient::class)))->computeUnattributedVariants($product, [
            'main_product_image_locator' => [['media_location' => 'https://tools.example/second.jpg']],
        ]);

        $this->assertSame([], $unattributed);
    }

    public function test_audit_one_writes_a_row_with_the_computed_unattributed_variants(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => [
                'main_product_image_locator' => [['media_location' => 'https://amazon.example/main.jpg']],
            ]]),
        ]);
        $product = $this->product();

        $success = (new ImageAuditService(app(SpApiClient::class)))->auditOne($product);

        $this->assertTrue($success);
        $audit = ImageAudit::firstOrFail();
        $this->assertSame('MAIN', $audit->unattributed_variants);
        $this->assertSame(1, $audit->unattributed_count);
        $this->assertNull($audit->not_live_variants);
        $this->assertSame(0, $audit->not_live_count);
        $this->assertNull($audit->fetch_error);
    }

    public function test_audit_one_writes_not_live_variants_independently_of_unattributed(): void
    {
        $product = $this->product();
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'main_product_image_locator',
            'old_value' => '[]', 'new_value' => json_encode([['media_location' => 'https://tools.example/main.jpg']]), 'status' => 'ACCEPTED',
        ]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => [
                'other_product_image_locator_1' => [['media_location' => 'https://amazon.example/pt01.jpg']],
            ]]),
        ]);

        $success = (new ImageAuditService(app(SpApiClient::class)))->auditOne($product);

        $this->assertTrue($success);
        $audit = ImageAudit::firstOrFail();
        $this->assertSame('PT01', $audit->unattributed_variants);
        $this->assertSame(1, $audit->unattributed_count);
        $this->assertSame('MAIN', $audit->not_live_variants);
        $this->assertSame(1, $audit->not_live_count);
    }

    public function test_a_failed_call_is_recorded_as_a_fetch_error_and_returns_false(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response([], 500),
        ]);
        $product = $this->product();

        $success = (new ImageAuditService(app(SpApiClient::class)))->auditOne($product);

        $this->assertFalse($success);
        $audit = ImageAudit::firstOrFail();
        $this->assertNotNull($audit->fetch_error);
        $this->assertNull($audit->unattributed_count);
        $this->assertNull($audit->not_live_count);
    }

    public function test_audit_batch_reports_done_once_every_active_product_is_processed(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
        ]);
        Product::factory()->count(3)->create(['active' => true, 'type' => 'DRINK_FLAVORED']);
        $service = new ImageAuditService(app(SpApiClient::class));

        $first = $service->auditBatch(0, 2);
        $this->assertSame(2, $first['processed']);
        $this->assertFalse($first['done']);

        $second = $service->auditBatch($first['nextOffset'], 2);
        $this->assertSame(1, $second['processed']);
        $this->assertTrue($second['done']);
    }
}
