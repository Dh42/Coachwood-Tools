<?php

namespace Tests\Feature\Tools;

use App\Models\ScListingAudit;
use App\Models\ScListingEdit;
use App\Models\ScProduct;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SellerListingTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $this->tool = Tool::where('slug', 'sc-listings')->firstOrFail();

        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'id',
            'services.sp_api.client_secret' => 'secret',
            'services.sp_api.seller_refresh_token' => 'seller-token',
            'services.sp_api.seller_id' => 'A0000000000000',
            'services.sp_api.seller_marketplace_id' => 'ATVPDKIKX0DER',
        ]);

        // These tests are the old app's, where submissions always went to
        // Amazon. Writes are off by default here (plan section 2, rule 3);
        // ListingsEditorFixesTest covers that path.
        config(['tools.external_writes' => true]);

        Http::fake(['api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake'])]);
        // See VendorListingTest::setUp() — the array cache driver persists
        // a faked product-type schema across test methods in this run.
        Cache::flush();
    }

    protected function fakeListingsItem(array $attributes = ['item_name' => [['value' => 'Old Title']]], array $schemaProperties = []): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => $attributes, 'productType' => 'DRINK_FLAVORED']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['productTypes' => [['productType' => 'DRINK_FLAVORED']], 'images' => [['images' => []]]]),
            'sp-api.test/definitions/2020-09-01/productTypes/*' => Http::response(['schema' => ['link' => ['resource' => 'https://schema.test/doc']]]),
            'schema.test/*' => Http::response(['properties' => $schemaProperties]),
        ]);
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    public function test_users_without_the_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.sc-listings.index'))->assertForbidden();
    }

    /** CDQ only ever scores Vendor Central ASINs — the shared _product-row partial gates that column behind $channel->includeVendorDetails(), false for Seller. */
    public function test_the_cdq_column_never_appears_on_the_seller_page(): void
    {
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'Listing A', 'status' => 'Active', 'synced_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index'));

        $response->assertOk();
        $response->assertDontSee('grade-badge', false);
        $response->assertDontSeeText('CDQ');
    }

    public function test_a_multi_sku_asin_appears_as_two_independently_editable_rows(): void
    {
        // Regression test for a real bug found in the legacy tool: it
        // silently resolved to whichever SKU loaded first, with no way to
        // pick the right one. Here, routing by SKU means both rows exist
        // and are independently selectable/editable.
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'Listing A', 'status' => 'Active', 'synced_at' => now()]);
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-B', 'title' => 'Listing B', 'status' => 'Active', 'synced_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index'));

        $response->assertOk()->assertSee('Listing A')->assertSee('Listing B');

        $this->fakeListingsItem(['item_name' => [['value' => 'B Title']]]);

        $selected = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index', ['sku' => 'SKU-B']));
        $selected->assertOk()->assertSee('B Title');
    }

    public function test_submit_uses_the_correct_seller_identity_path_segment(): void
    {
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'T', 'status' => 'Active', 'synced_at' => now()]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::sequence()
                ->push(['status' => 'VALID'])
                ->push(['status' => 'ACCEPTED', 'submissionId' => 'sub-1']),
        ]);

        $user = $this->userWithAccess();
        $this->actingAs($user)->post(route('tools.sc-listings.submit'), [
            'asin' => 'B1', 'sku' => 'SKU-A', 'product_type' => 'DRINK_FLAVORED',
            'attr' => ['item_name' => [0 => ['value' => 'New Title']]],
            'orig' => ['item_name' => json_encode([['value' => 'Old Title']])],
        ]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/items/A0000000000000/SKU-A'));
        $this->assertSame($user->id, ScListingEdit::firstOrFail()->user_id);
        $this->assertSame('ACCEPTED', ScListingEdit::firstOrFail()->status);
    }

    public function test_aplus_check_uses_ebc_content_type_for_seller_channel(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => [['contentType' => 'EBC']]]),
        ]);
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'T', 'status' => 'Active', 'synced_at' => now()]);

        $this->actingAs($this->userWithAccess())->post(route('tools.sc-listings.audit.run'));

        $this->assertSame('full', ScListingAudit::firstOrFail()->aplus_status);
    }

    public function test_sync_products_parses_the_report_and_upserts_by_sku(): void
    {
        $tsv = "seller-sku\tasin1\titem-name\tstatus\nSKU-A\tB1\tListing A\tActive\nSKU-B\tB2\tListing B\tInactive\n";

        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/reports/2021-06-30/reports' => Http::response(['reportId' => 'r1'], 202),
            'sp-api.test/reports/2021-06-30/reports/r1' => Http::response(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1']),
            'sp-api.test/reports/2021-06-30/documents/doc-1' => Http::response(['url' => 'https://download.test/report.tsv']),
            'download.test/report.tsv' => Http::response($tsv),
        ]);

        $response = $this->actingAs($this->userWithAccess())->post(route('tools.sc-listings.sync'));

        $response->assertRedirect();
        $this->assertSame(2, ScProduct::count());
        $this->assertSame('Listing A', ScProduct::where('sku', 'SKU-A')->value('title'));
        $this->assertSame('Inactive', ScProduct::where('sku', 'SKU-B')->value('status'));
    }

    public function test_sync_products_is_idempotent_on_sku(): void
    {
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'Old Title', 'status' => 'Active', 'synced_at' => now()->subDay()]);
        $tsv = "seller-sku\tasin1\titem-name\tstatus\nSKU-A\tB1\tNew Title\tActive\n";

        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/reports/2021-06-30/reports' => Http::response(['reportId' => 'r1'], 202),
            'sp-api.test/reports/2021-06-30/reports/r1' => Http::response(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1']),
            'sp-api.test/reports/2021-06-30/documents/doc-1' => Http::response(['url' => 'https://download.test/report.tsv']),
            'download.test/report.tsv' => Http::response($tsv),
        ]);

        $this->actingAs($this->userWithAccess())->post(route('tools.sc-listings.sync'));

        $this->assertSame(1, ScProduct::count());
        $this->assertSame('New Title', ScProduct::firstOrFail()->title);
    }

    public function test_sync_start_kicks_off_a_report_and_returns_its_id(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/reports/2021-06-30/reports' => Http::response(['reportId' => 'r1'], 202),
        ]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.sc-listings.sync.start'));

        $response->assertOk()->assertJson(['status' => 'processing', 'reportId' => 'r1']);
    }

    public function test_sync_poll_reports_processing_while_amazon_still_has_the_report_queued(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/reports/2021-06-30/reports/r1' => Http::response(['processingStatus' => 'IN_QUEUE']),
        ]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.sc-listings.sync.poll'), ['reportId' => 'r1']);

        $response->assertOk()->assertJson(['status' => 'processing']);
        $this->assertSame(0, ScProduct::count());
    }

    public function test_sync_poll_imports_and_returns_done_once_the_report_is_ready(): void
    {
        $tsv = "seller-sku\tasin1\titem-name\tstatus\nSKU-A\tB1\tListing A\tActive\n";
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/reports/2021-06-30/reports/r1' => Http::response(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1']),
            'sp-api.test/reports/2021-06-30/documents/doc-1' => Http::response(['url' => 'https://download.test/report.tsv']),
            'download.test/report.tsv' => Http::response($tsv),
        ]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.sc-listings.sync.poll'), ['reportId' => 'r1']);

        $response->assertOk()->assertJson(['status' => 'done', 'count' => 1]);
        $this->assertSame('Listing A', ScProduct::where('sku', 'SKU-A')->value('title'));
    }

    public function test_sync_poll_reports_failure_when_the_report_itself_fails(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/reports/2021-06-30/reports/r1' => Http::response(['processingStatus' => 'FATAL']),
        ]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.sc-listings.sync.poll'), ['reportId' => 'r1']);

        $response->assertServerError()->assertJson(['status' => 'failed']);
    }

    public function test_audit_batch_returns_fresh_row_html_for_real_time_updates(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => ['bullet_point' => [['value' => 'A']]]]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []]),
        ]);
        $product = ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'Row Product', 'status' => 'Active', 'synced_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.sc-listings.audit.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($product->id, $rows[0]['id']);
        $this->assertStringContainsString('id="row-'.$product->id.'"', $rows[0]['html']);
        $this->assertStringContainsString('Row Product', $rows[0]['html']);
        $this->assertNotNull($response->json('lastFullAudit'));
    }

    public function test_a_schema_constrained_field_renders_as_a_dropdown_with_amazons_own_options(): void
    {
        $this->fakeListingsItem(
            attributes: ['item_package_weight' => [['value' => 5, 'unit' => 'KG']]],
            schemaProperties: [
                'item_package_weight' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['type' => 'number'],
                            'unit' => ['type' => 'string', 'enum' => ['GR', 'KG', 'LB'], 'enumNames' => ['Grams', 'Kilograms', 'Pounds']],
                        ],
                    ],
                ],
            ],
        );
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'T', 'status' => 'Active', 'synced_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index', ['sku' => 'SKU-A']));

        $response->assertOk();
        $response->assertSee('name="attr[item_package_weight][0][unit]"', false);
        $response->assertSee('<select', false);
        $response->assertSee('Kilograms');
    }

    public function test_contribution_mismatched_field_is_highlighted_in_the_editor(): void
    {
        $this->fakeListingsItem(['item_name' => [['value' => 'Old Title']]]);
        $product = ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'T', 'status' => 'Active', 'synced_at' => now()]);
        ScListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 1, 'contribution_mismatch_attrs' => 'item_name', 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index', ['sku' => 'SKU-A']));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertSame(1, substr_count($body, 'doesn&#039;t match our last accepted submission'));
    }

    public function test_contribution_mismatch_summary_lists_every_flagged_sku(): void
    {
        $product = ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'Mismatched Listing', 'status' => 'Active', 'synced_at' => now()]);
        ScListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 1, 'contribution_mismatch_attrs' => 'form_factor', 'checked_at' => now()]);
        $clean = ScProduct::create(['asin' => 'B2', 'sku' => 'SKU-B', 'title' => 'Clean Listing', 'status' => 'Active', 'synced_at' => now()]);
        ScListingAudit::create(['product_id' => $clean->id, 'contribution_mismatch_count' => 0, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index'));

        $response->assertOk();
        $response->assertSee('Not showing our contribution — 1 product(s)');
        $response->assertSee('form_factor');
        $body = $response->getContent();
        // Mismatched Listing: once in the summary box link, plus twice in
        // its main picker table row (title="" attribute + link text) = 3.
        // Clean Listing should only ever appear those latter two, never in
        // the summary — assertDontSee would be wrong here since the picker
        // table lists every active SKU regardless.
        $this->assertSame(3, substr_count($body, 'Mismatched Listing'));
        $this->assertSame(2, substr_count($body, 'Clean Listing'));
    }

    public function test_contribution_mismatch_summary_is_absent_when_nothing_is_flagged(): void
    {
        $product = ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'Clean Listing', 'status' => 'Active', 'synced_at' => now()]);
        ScListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 0, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index'));

        $response->assertOk();
        $response->assertDontSee('Not showing our contribution');
    }

    public function test_search_input_and_filter_tiles_render_but_not_the_vc_only_ones(): void
    {
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'A Listing', 'status' => 'Active', 'synced_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index'));

        $response->assertOk()
            ->assertSee('id="sc-search"', false)
            ->assertSee('data-filter="mismatch"', false)
            ->assertSee('data-filter="noaplus"', false)
            ->assertDontSee('data-filter="issues"', false)
            ->assertDontSee('data-filter="cdq-attention"', false);
    }

    public function test_a_row_carries_the_mismatch_filter_token_when_contribution_has_drifted(): void
    {
        $product = ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'Mismatched Listing', 'status' => 'Active', 'synced_at' => now()]);
        ScListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 2, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index'));

        $response->assertOk();
        $this->assertMatchesRegularExpression('/data-filters="[^"]*\bmismatch\b[^"]*"/', $response->getContent());
    }

    public function test_a_row_carries_the_search_blob_with_title_asin_and_sku(): void
    {
        ScProduct::create(['asin' => 'B0SEARCHME', 'sku' => 'SEARCH-SKU', 'title' => 'Findable Widget', 'status' => 'Active', 'synced_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.sc-listings.index'));

        $response->assertOk();
        $this->assertStringContainsString('data-search="findable widget b0searchme search-sku"', $response->getContent());
    }
}
