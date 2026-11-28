<?php

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\Listings\VendorListingController;
use App\Models\CdqCheckConfigVersion;
use App\Models\CdqListingSnapshot;
use App\Models\CdqScore;
use App\Models\ListingAudit;
use App\Models\ListingAuditAttributeCatalog;
use App\Models\ListingAuditConfig;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Models\Tool;
use App\Models\User;
use Database\Seeders\CdqCheckConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorListingTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $this->tool = Tool::where('slug', 'vc-listings')->firstOrFail();

        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'id',
            'services.sp_api.client_secret' => 'secret',
            'services.sp_api.vendor_refresh_token' => 'vendor-token',
            'services.sp_api.vendor_code' => 'Z0000',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
        ]);

        // These tests are the old app's, where submissions always went to
        // Amazon. Writes are off by default here (plan section 2, rule 3);
        // ListingsEditorFixesTest covers that path.
        config(['tools.external_writes' => true]);

        Http::fake(['api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake'])]);
        // The product-type schema is cached ~24h per channel+productType (see
        // SpApiClient::getProductTypeSchema()) — the array cache driver used
        // in tests otherwise persists across test methods in this run, so a
        // schema faked by one test could leak into the next one's assertions.
        Cache::flush();
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    /** @return array<int, string> the export's column keys, in order — exportColumns() is protected, not part of any route response. */
    protected function exportColumnKeys(): array
    {
        $controller = app(VendorListingController::class);

        return array_keys((new \ReflectionMethod($controller, 'exportColumns'))->invoke($controller));
    }

    /**
     * $classifications, when given, is folded into the same Catalog Items
     * response both getCatalogItem() and getCatalogItemWithIncludedData()
     * hit (same URL pattern, same Http::fake wildcard — the includedData
     * query param isn't part of the match) — harmless for the base call,
     * which never looks for a classifications key in what it gets back.
     */
    protected function fakeListingsItem(array $attributes = ['item_name' => [['value' => 'Old Title']]], array $schemaProperties = [], array $classifications = []): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => $attributes, 'productType' => 'DRINK_FLAVORED']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(array_filter([
                'productTypes' => [['productType' => 'DRINK_FLAVORED']],
                'images' => [['images' => []]],
                'classifications' => $classifications ?: null,
            ], fn ($v) => $v !== null)),
            'sp-api.test/definitions/2020-09-01/productTypes/*' => Http::response(['schema' => ['link' => ['resource' => 'https://schema.test/doc']]]),
            'schema.test/*' => Http::response(['properties' => $schemaProperties]),
        ]);
    }

    public function test_users_without_the_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.vc-listings.index'))->assertForbidden();
    }

    public function test_admins_can_access_without_the_permission_checkbox(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('tools.vc-listings.index'))->assertOk();
    }

    public function test_picker_shows_active_products(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Visible Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk()->assertSee('Visible Product');
    }

    public function test_li_column_shows_a_green_circle_when_the_last_audit_reported_no_issues(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Clean Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'listing_issues' => [], 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk()->assertSee('🟢');
    }

    public function test_li_column_shows_a_red_circle_and_the_issue_text_in_a_tooltip_when_issues_are_open(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Suppressed Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'checked_at' => now(), 'listing_issues' => [[
            'code' => '18448', 'message' => 'Missing required attribute', 'severity' => 'ERROR',
            'categories' => ['MISSING_ATTRIBUTE'], 'enforcements' => ['actions' => [['action' => 'LISTING_SUPPRESSED']]],
        ]]]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk()->assertSee('🔴');
        $response->assertSee('ERROR: Missing required attribute [LISTING_SUPPRESSED]', false);
    }

    public function test_li_column_shows_a_dash_when_never_audited(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Unaudited Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        // The Audit column key legend always mentions 🟢/🔴 regardless of
        // any product's audit state, so this scopes to just this
        // product's own row rather than the whole page.
        $response->assertOk();
        $rowHtml = Str::of($response->getContent())->after('id="row-'.$product->id.'"')->before('</tr>');
        $this->assertStringNotContainsString('🟢', $rowHtml);
        $this->assertStringNotContainsString('🔴', $rowHtml);
        $this->assertStringContainsString('—', $rowHtml);
    }

    public function test_cdq_column_shows_the_grade_badge_and_links_to_the_report_when_scored(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Scored Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        $this->seed(CdqCheckConfigSeeder::class);
        $version = CdqCheckConfigVersion::orderByDesc('effective_from')->first();
        $snapshot = CdqListingSnapshot::create([
            'asin' => 'B1', 'marketplace_id' => 'A2EUQ1WTGCTBG2', 'content_hash' => hash('sha256', 'x'),
            'catalog_json' => ['images' => [['images' => []]]], 'attributes_json' => [], 'issues_checked_at' => now(), 'captured_at' => now(),
        ]);
        CdqScore::create([
            'snapshot_id' => $snapshot->id, 'check_config_version_id' => $version->id,
            'attr_score' => 20, 'text_score' => 15, 'image_score' => 15, 'aplus_score' => 15, 'consist_score' => 10,
            'total_score' => 75, 'grade' => 'B', 'scored_at' => now(),
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk();
        $rowHtml = Str::of($response->getContent())->after('id="row-'.$product->id.'"')->before('</tr>');
        $this->assertStringContainsString(route('tools.cdq-report.show', 'B1'), $rowHtml);
        $this->assertStringContainsString('>B<', $rowHtml);
    }

    public function test_cdq_column_shows_a_dash_when_never_scored(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Unscored Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk();
        $rowHtml = Str::of($response->getContent())->after('id="row-'.$product->id.'"')->before('</tr>');
        $this->assertStringNotContainsString('grade-badge', $rowHtml);
        $this->assertStringContainsString('—', $rowHtml);
    }

    public function test_selecting_a_product_loads_the_editor_with_live_data(): void
    {
        $this->fakeListingsItem();
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk()->assertSee('Old Title');
    }

    public function test_editor_shows_the_listing_issues_panel_expanded_with_full_text_when_issues_are_open(): void
    {
        $this->fakeListingsItem();
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'checked_at' => now(), 'listing_issues' => [[
            'code' => '18448', 'message' => 'Missing required attribute', 'severity' => 'ERROR',
            'attributeNames' => ['item_weight'], 'enforcements' => ['actions' => [['action' => 'LISTING_SUPPRESSED']]],
        ]]]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk();
        $response->assertSee('Listing Issues');
        $response->assertSee('Missing required attribute');
        $response->assertSee('LISTING_SUPPRESSED');
        $response->assertSee('item_weight');
        // Auto-expanded, not left collapsed for the user to discover. The old
        // page used a Bootstrap accordion ("collapse show"); the new design
        // uses a <details> element, open.
        $this->assertMatchesRegularExpression('/<details[^>]*id="listing-issues"[^>]*\sopen[\s>]/', $response->getContent());
    }

    public function test_editor_shows_the_listing_issues_panel_collapsed_when_none_are_open(): void
    {
        $this->fakeListingsItem();
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'checked_at' => now(), 'listing_issues' => []]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk();
        $response->assertSee('None open');
        $response->assertSee('No open Listings Items issues as of the last audit.');
    }

    public function test_editor_shows_listing_restrictions_with_a_path_forward_link_when_present(): void
    {
        $this->fakeListingsItem();
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'checked_at' => now(), 'listing_restrictions' => [[
            'conditionType' => 'new_new',
            'reasons' => [[
                'message' => 'Approval required.', 'reasonCode' => 'APPROVAL_REQUIRED',
                'links' => [['resource' => 'https://sellercentral.amazon.com/approve?asin=B1', 'title' => 'Request Approval']],
            ]],
        ]]]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk();
        $response->assertSee('Listing Restrictions');
        $response->assertSee('APPROVAL_REQUIRED');
        $response->assertSee('Approval required.');
        $response->assertSee('https://sellercentral.amazon.com/approve?asin=B1', false);
    }

    public function test_editor_shows_restrictions_as_unavailable_when_the_call_never_returned_data(): void
    {
        $this->fakeListingsItem();
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'checked_at' => now(), 'listing_restrictions' => null]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk()->assertSee('Unavailable');
    }

    public function test_ajax_request_returns_only_the_editor_fragment(): void
    {
        $this->fakeListingsItem();
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1', 'ajax' => 1]));

        $response->assertOk();
        $response->assertDontSee('Coachwood Group Tools');
        $response->assertSee('Old Title');
    }

    public function test_submit_with_no_actual_change_makes_no_api_call_and_flashes_nothing_changed(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->post(route('tools.vc-listings.submit'), [
            'asin' => 'B1', 'sku' => 'SKU-1', 'product_type' => 'DRINK_FLAVORED',
            'attr' => ['item_name' => [0 => ['value' => 'Same Title']]],
            'orig' => ['item_name' => json_encode([['value' => 'Same Title']])],
        ]);

        $response->assertRedirect();
        $this->assertSame('Nothing changed — no submission made.', session('status'));
        $this->assertSame(0, ListingEdit::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'listings/2021-08-01'));
    }

    public function test_a_real_change_is_previewed_then_submitted_and_logged(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::sequence()
                ->push(['status' => 'VALID'])
                ->push(['status' => 'ACCEPTED', 'submissionId' => 'sub-1']),
        ]);

        $user = $this->userWithAccess();
        $response = $this->actingAs($user)->post(route('tools.vc-listings.submit'), [
            'asin' => 'B1', 'sku' => 'SKU-1', 'product_type' => 'DRINK_FLAVORED',
            'attr' => ['item_name' => [0 => ['value' => 'New Title']]],
            'orig' => ['item_name' => json_encode([['value' => 'Old Title']])],
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('accepted by Amazon', session('status'));

        $edit = ListingEdit::firstOrFail();
        $this->assertSame('item_name', $edit->attribute);
        $this->assertSame('ACCEPTED', $edit->status);
        $this->assertSame('sub-1', $edit->submission_id);
        $this->assertSame($user->id, $edit->user_id);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'mode=VALIDATION_PREVIEW'));
    }

    public function test_imported_historical_edits_show_no_user_attribution(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        $edit = ListingEdit::create([
            'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'item_name',
            'old_value' => '[]', 'new_value' => '[]', 'status' => 'ACCEPTED', 'user_id' => null,
        ]);

        $this->assertNull($edit->fresh()->user_id);
        $this->assertNull($edit->fresh()->user);
    }

    public function test_a_preview_rejection_stops_before_the_real_submission_and_logs_nothing(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['status' => 'INVALID', 'issues' => [['severity' => 'ERROR', 'message' => 'Too long']]]),
        ]);

        $response = $this->actingAs($this->userWithAccess())->post(route('tools.vc-listings.submit'), [
            'asin' => 'B1', 'sku' => 'SKU-1', 'product_type' => 'DRINK_FLAVORED',
            'attr' => ['item_name' => [0 => ['value' => 'New Title']]],
            'orig' => ['item_name' => json_encode([['value' => 'Old Title']])],
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('Amazon rejected this change', session('error'));
        $this->assertSame(0, ListingEdit::count());
        Http::assertSentCount(2); // token + the one preview call, never a second (real) PATCH
    }

    public function test_a_rejected_real_submission_is_still_logged_to_listing_edits(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::sequence()
                ->push(['status' => 'VALID'])
                ->push(['status' => 'REJECTED', 'submissionId' => 'sub-2']),
        ]);

        $this->actingAs($this->userWithAccess())->post(route('tools.vc-listings.submit'), [
            'asin' => 'B1', 'sku' => 'SKU-1', 'product_type' => 'DRINK_FLAVORED',
            'attr' => ['item_name' => [0 => ['value' => 'New Title']]],
            'orig' => ['item_name' => json_encode([['value' => 'Old Title']])],
        ]);

        $edit = ListingEdit::firstOrFail();
        $this->assertSame('REJECTED', $edit->status);
    }

    public function test_audit_run_records_a_run_for_every_active_product(): void
    {
        $this->fakeListingsItem(['bullet_point' => [['value' => 'A']]]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => ['bullet_point' => [['value' => 'A']]]]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['vendorDetails' => [[]], 'images' => [['images' => []]]]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->post(route('tools.vc-listings.audit.run'));

        $response->assertRedirect();
        $this->assertSame(1, ListingAudit::count());
    }

    public function test_audit_batch_returns_json_progress(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['vendorDetails' => [[]], 'images' => [['images' => []]]]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.audit.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk()->assertJson(['processed' => 1, 'done' => true]);
    }

    public function test_audit_batch_returns_fresh_row_html_for_real_time_updates(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => ['bullet_point' => [['value' => 'A']]]]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['vendorDetails' => [['brandCode' => 'BIOS']], 'images' => [['images' => []]]]),
            'sp-api.test/aplus/2020-11-01/*' => Http::response(['publishRecordList' => []]),
        ]);
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Row Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.audit.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($product->id, $rows[0]['id']);
        $this->assertStringContainsString('id="row-'.$product->id.'"', $rows[0]['html']);
        $this->assertStringContainsString('Row Product', $rows[0]['html']);
        $this->assertStringContainsString('BIOS', $rows[0]['html']); // fresh audit data, not a stale pre-audit snapshot
        $this->assertNotNull($response->json('lastFullAudit')); // present once the batch is done
    }

    public function test_export_batch_returns_one_row_per_product_with_asin_sku_title_and_product_type_first(): void
    {
        ListingAuditAttributeCatalog::create(['attribute_key' => 'bullet_point', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response([
                'attributes' => ['item_name' => [['value' => 'Real Title']], 'bullet_point' => [['value' => 'A'], ['value' => 'B']]],
                'productType' => 'DRINK_FLAVORED',
            ]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Local Title', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.export.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk()->assertJson(['processed' => 1, 'total' => 1, 'done' => true]);
        $row = $response->json('rows')[0];
        // The identity columns (asin/sku/title/product_type) are read from
        // the local Product row, not the live listing — deliberately, so a
        // row still has something useful in it even when the live fetch
        // below fails outright (see the fetch_error test). The live
        // item_name value still shows up too, just as its own
        // dynamic 'Item Name' attribute column, not this fixed one.
        $this->assertSame(['B1', 'SKU-1', 'Local Title', 'DRINK_FLAVORED'], array_slice($row, 0, 4));
        $columns = $this->exportColumnKeys();
        $bulletCol = array_search('bullet_point', $columns);
        $this->assertSame('A; B', $row[$bulletCol]);
    }

    public function test_export_batch_includes_the_browse_node_friendly_name(): void
    {
        ListingAuditAttributeCatalog::create(['attribute_key' => 'recommended_browse_nodes', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response([
                'attributes' => ['recommended_browse_nodes' => [['value' => '14255183011']]],
                'productType' => 'DRINK_FLAVORED',
            ]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([
                'classifications' => [[
                    'marketplaceId' => 'A2EUQ1WTGCTBG2',
                    'classifications' => [['displayName' => 'Recovery & Hydration Drinks', 'classificationId' => '14255183011']],
                ]],
            ]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $browseNodeCol = array_search('recommended_browse_nodes', $this->exportColumnKeys());

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.export.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk();
        $this->assertSame('14255183011 — Recovery & Hydration Drinks', $response->json('rows')[0][$browseNodeCol]);
    }

    public function test_export_batch_includes_brand_code_and_manufacturer_code_from_vendor_details(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => [], 'productType' => 'DRINK_FLAVORED']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([
                'vendorDetails' => [['brandCode' => 'BIOS', 'manufacturerCode' => 'MFR1']],
            ]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $columns = $this->exportColumnKeys();
        $brandCodeCol = array_search('brand_code', $columns);
        $mfrCodeCol = array_search('manufacturer_code', $columns);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.export.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk();
        $row = $response->json('rows')[0];
        $this->assertSame('BIOS', $row[$brandCodeCol]);
        $this->assertSame('MFR1', $row[$mfrCodeCol]);
    }

    public function test_export_batch_leaves_brand_and_manufacturer_code_blank_when_vendor_details_is_missing(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => [], 'productType' => 'DRINK_FLAVORED']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $columns = $this->exportColumnKeys();
        $brandCodeCol = array_search('brand_code', $columns);
        $mfrCodeCol = array_search('manufacturer_code', $columns);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.export.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk();
        $row = $response->json('rows')[0];
        $this->assertSame('', $row[$brandCodeCol]);
        $this->assertSame('', $row[$mfrCodeCol]);
    }

    public function test_export_batch_strips_the_language_tag_from_a_single_value_attribute(): void
    {
        ListingAuditAttributeCatalog::create(['attribute_key' => 'brand', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response([
                'attributes' => ['brand' => [['value' => 'BioSteel Sports', 'language_tag' => 'en_CA']]],
                'productType' => 'DRINK_FLAVORED',
            ]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $brandCol = array_search('brand', $this->exportColumnKeys());

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.export.batch'), ['offset' => 0, 'batch' => 5]);

        // Without stripping language_tag first, this would export as
        // "value=BioSteel Sports, language_tag=en_CA" instead of the plain,
        // spreadsheet-editable value — the editor already drops language_tag
        // the same way via humanizeLeafPath().
        $response->assertOk();
        $this->assertSame('BioSteel Sports', $response->json('rows')[0][$brandCol]);
    }

    public function test_export_batch_still_renders_a_genuinely_multi_leaf_attribute_as_key_value_pairs(): void
    {
        ListingAuditAttributeCatalog::create(['attribute_key' => 'list_price', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response([
                'attributes' => ['list_price' => [['currency' => 'CAD', 'value' => 34.99]]],
                'productType' => 'DRINK_FLAVORED',
            ]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $priceCol = array_search('list_price', $this->exportColumnKeys());

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.export.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk();
        $this->assertSame('currency=CAD, value=34.99', $response->json('rows')[0][$priceCol]);
    }

    public function test_export_batch_records_a_fetch_error_without_failing_the_whole_batch(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response('error', 500),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $errorCol = array_search('fetch_error', $this->exportColumnKeys());

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.export.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk()->assertJson(['processed' => 1]);
        $row = $response->json('rows')[0];
        $this->assertSame('B1', $row[0]);
        $this->assertStringContainsString('HTTP 500', $row[$errorCol]);
    }

    public function test_export_batch_paginates_like_audit_batch(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T1', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Product::create(['asin' => 'B2', 'sku' => 'SKU-2', 'title' => 'T2', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-listings.export.batch'), ['offset' => 0, 'batch' => 1]);

        $response->assertOk()->assertJson(['processed' => 1, 'total' => 2, 'done' => false, 'nextOffset' => 1]);
    }

    public function test_export_endpoints_are_forbidden_without_the_tool_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->postJson(route('tools.vc-listings.export.batch'))->assertForbidden();
    }

    public function test_audit_config_update_persists_a_real_user_attribution(): void
    {
        ListingAuditAttributeCatalog::create(['attribute_key' => 'bullet_point', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('tools.vc-listings.audit.config'), [
            'attr_slot_1' => 'bullet_point', 'config_reason' => 'testing',
        ]);

        $config = ListingAuditConfig::firstOrFail();
        $this->assertSame('bullet_point', $config->attr_slot_1);
        $this->assertSame($admin->id, $config->changed_by_user_id);
    }

    public function test_a_schema_constrained_field_renders_as_a_dropdown_with_amazons_own_options(): void
    {
        // Mirrors a real legacy-tool behavior request: any attribute
        // Amazon's own schema restricts to a fixed set of values (e.g. a
        // weight unit) should be a <select>, not free text a user could
        // type an invalid value into.
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
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk();
        $response->assertSee('name="attr[item_package_weight][0][unit]"', false);
        $response->assertSee('<select', false);
        $response->assertSee('Kilograms');
        // The unconstrained sibling field in the same row must still be a
        // plain input — enum-detection shouldn't blanket the whole item.
        $response->assertSee('name="attr[item_package_weight][0][value]" value="5"', false);
    }

    public function test_recommended_browse_nodes_shows_the_friendly_name_alongside_the_raw_node_id(): void
    {
        $this->fakeListingsItem(
            attributes: ['recommended_browse_nodes' => [['value' => '14255183011']]],
            classifications: [[
                'marketplaceId' => 'A2EUQ1WTGCTBG2',
                'classifications' => [[
                    'displayName' => 'Recovery & Hydration Drinks',
                    'classificationId' => '14255183011',
                    'parent' => ['displayName' => 'Sports Drinks', 'classificationId' => '7351133011'],
                ]],
            ]],
        );
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk();
        $response->assertSee('name="attr[recommended_browse_nodes][0][value]" value="14255183011"', false);
        $response->assertSee('Recovery & Hydration Drinks');
    }

    public function test_recommended_browse_nodes_shows_just_the_raw_id_when_no_classification_matches(): void
    {
        $this->fakeListingsItem(attributes: ['recommended_browse_nodes' => [['value' => '99999999999']]]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk();
        $response->assertSee('name="attr[recommended_browse_nodes][0][value]" value="99999999999"', false);
    }

    public function test_contribution_mismatched_field_is_highlighted_in_the_editor(): void
    {
        $this->fakeListingsItem(['item_name' => [['value' => 'Old Title']], 'bullet_point' => [['value' => 'A']]]);
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 1, 'contribution_mismatch_attrs' => 'item_name', 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index', ['asin' => 'B1']));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertSame(1, substr_count($body, 'doesn&#039;t match our last accepted submission'));
    }

    public function test_contribution_mismatch_summary_lists_every_flagged_product(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Mismatched Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 2, 'contribution_mismatch_attrs' => 'item_name,bullet_point', 'checked_at' => now()]);
        $clean = Product::create(['asin' => 'B2', 'sku' => 'SKU-2', 'title' => 'Clean Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $clean->id, 'contribution_mismatch_count' => 0, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk();
        $response->assertSee('Not showing our contribution — 1 product(s)');
        $response->assertSee('item_name, bullet_point');
        $body = $response->getContent();
        // Mismatched Product: once in the summary box link, plus twice in
        // its main picker table row (title="" attribute + link text) = 3.
        // Clean Product should only ever appear those latter two, never in
        // the summary — assertDontSee would be wrong here since the picker
        // table lists every active product regardless.
        $this->assertSame(3, substr_count($body, 'Mismatched Product'));
        $this->assertSame(2, substr_count($body, 'Clean Product'));
    }

    public function test_contribution_mismatch_summary_is_absent_when_nothing_is_flagged(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Clean Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 0, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk();
        $response->assertDontSee('Not showing our contribution');
    }

    public function test_search_input_and_filter_tiles_render(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'A Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk()
            ->assertSee('id="vc-search"', false)
            ->assertSee('data-filter="mismatch"', false)
            ->assertSee('data-filter="noaplus"', false)
            ->assertSee('data-filter="issues"', false)
            ->assertSee('data-filter="cdq-attention"', false);
    }

    public function test_a_row_carries_the_mismatch_filter_token_when_contribution_has_drifted(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Mismatched Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 2, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk();
        $this->assertMatchesRegularExpression('/data-filters="[^"]*\bmismatch\b[^"]*"/', $response->getContent());
    }

    public function test_a_row_does_not_carry_the_mismatch_filter_token_when_contribution_matches(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Clean Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingAudit::create(['product_id' => $product->id, 'contribution_mismatch_count' => 0, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression('/data-filters="[^"]*\bmismatch\b[^"]*"/', $response->getContent());
    }

    public function test_a_row_carries_the_search_blob_with_title_asin_and_sku(): void
    {
        Product::create(['asin' => 'B0SEARCHME', 'sku' => 'SEARCH-SKU', 'title' => 'Findable Widget', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk();
        $this->assertStringContainsString('data-search="findable widget b0searchme search-sku"', $response->getContent());
    }

    public function test_last_checked_cell_carries_a_numeric_sort_value(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Timed Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        $checkedAt = now()->subHour();
        ListingAudit::create(['product_id' => $product->id, 'checked_at' => $checkedAt]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-listings.index'));

        $response->assertOk();
        $this->assertStringContainsString('data-sort-value="'.$checkedAt->timestamp.'"', $response->getContent());
    }
}
