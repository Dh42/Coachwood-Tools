<?php

namespace Tests\Feature\Tools;

use App\Models\ListingAuditAttributeCatalog;
use App\Models\ListingAuditConfig;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Models\ScListingAudit;
use App\Models\ScListingEdit;
use App\Models\ScProduct;
use App\Models\Tool;
use App\Models\User;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\ListingChannel;
use App\Support\ExternalWritesDisabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * VC and SC Listings Editor behaviour that differs from the old app on
 * purpose: the write guard (plan section 2, rule 3) and the bugs fixed in
 * the port (docs/found-issues.md 9, 15 and 16). Every value is invented.
 */
class ListingsEditorFixesTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_HEADER = "seller-sku\tasin1\titem-name\tstatus\n";

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
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
        Cache::flush();
    }

    private function user(string $slug, string $role = 'user'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->givePermissionTo(Tool::where('slug', $slug)->firstOrFail()->permissionName());

        return $user;
    }

    private function titleChange(): array
    {
        return [
            'asin' => 'B1', 'sku' => 'SKU-1', 'product_type' => 'DRINK_FLAVORED',
            'attr' => ['item_name' => [0 => ['value' => 'New Title']]],
            'orig' => ['item_name' => json_encode([['value' => 'Old Title']])],
        ];
    }

    /** Each later sync gets the next report. (Http::fake keeps the first stub that matches, so it can't be re-faked.) */
    private function fakeReport(string ...$tsvs): void
    {
        $downloads = Http::sequence();
        foreach ($tsvs as $tsv) {
            $downloads->push($tsv);
        }
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/reports/2021-06-30/reports' => Http::response(['reportId' => 'r1'], 202),
            'sp-api.test/reports/2021-06-30/reports/r1' => Http::response(['processingStatus' => 'DONE', 'reportDocumentId' => 'doc-1']),
            'sp-api.test/reports/2021-06-30/documents/doc-1' => Http::response(['url' => 'https://download.test/report.tsv']),
            'download.test/report.tsv' => $downloads,
        ]);
    }

    public function test_with_writes_off_a_change_is_validated_but_not_submitted_or_logged(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Made-up Drink', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['status' => 'VALID']),
        ]);

        $response = $this->actingAs($this->user('vc-listings'))->post(route('tools.vc-listings.submit'), $this->titleChange());

        $response->assertRedirect(route('tools.vc-listings.index', ['asin' => 'B1']));
        $this->assertStringContainsString('nothing was sent to Amazon', session('warning'));
        $this->assertStringContainsString('item_name', session('warning'));
        $this->assertSame(0, ListingEdit::count());
        Http::assertSentCount(2); // the token and the one preview
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH' && ! str_contains($request->url(), 'mode=VALIDATION_PREVIEW'));
    }

    public function test_with_writes_off_the_seller_page_also_stops_after_the_preview(): void
    {
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Made-up Listing', 'status' => 'Active', 'synced_at' => now()]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['status' => 'VALID']),
        ]);

        $this->actingAs($this->user('sc-listings'))->post(route('tools.sc-listings.submit'), $this->titleChange())
            ->assertRedirect(route('tools.sc-listings.index', ['sku' => 'SKU-1']));

        $this->assertStringContainsString('nothing was sent to Amazon', session('warning'));
        $this->assertSame(0, ScListingEdit::count());
        Http::assertSentCount(2);
    }

    public function test_with_writes_off_the_client_refuses_a_real_patch_before_any_call(): void
    {
        Http::fake();

        try {
            app(SpApiClient::class)->patchListing('SKU-1', 'DRINK_FLAVORED', [], false, ListingChannel::Vendor);
            $this->fail('Expected the PATCH to be refused.');
        } catch (ExternalWritesDisabled $e) {
            $this->assertStringContainsString('Amazon listing PATCH', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_the_page_says_submissions_are_off_until_cutover(): void
    {
        $user = $this->user('vc-listings');

        $this->actingAs($user)->get(route('tools.vc-listings.index'))
            ->assertOk()->assertSee('Submissions are off in this app until cutover.');

        config(['tools.external_writes' => true]);
        $this->actingAs($user)->get(route('tools.vc-listings.index'))
            ->assertOk()->assertDontSee('Submissions are off in this app until cutover.');
    }

    /** Found issue 9: the README says the audit columns are admin-editable; any tool user could change them. */
    public function test_only_admins_can_change_the_audit_columns(): void
    {
        ListingAuditAttributeCatalog::create(['attribute_key' => 'bullet_point', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $user = $this->user('vc-listings');
        $user->givePermissionTo(Tool::where('slug', 'sc-listings')->firstOrFail()->permissionName());

        $this->actingAs($user)->post(route('tools.vc-listings.audit.config'), ['attr_slot_1' => 'bullet_point'])->assertForbidden();
        $this->actingAs($user)->post(route('tools.sc-listings.audit.config'), ['attr_slot_1' => 'bullet_point'])->assertForbidden();
        $this->assertSame(0, ListingAuditConfig::count());
        $this->actingAs($user)->get(route('tools.vc-listings.index'))->assertOk()->assertDontSee('Configure audit columns');

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('tools.vc-listings.index'))->assertOk()->assertSee('Configure audit columns');
    }

    /** Found issue 15: a SKU gone from the all-listings report kept its old status, so it stayed in the picker. */
    public function test_a_sync_marks_skus_missing_from_the_report_as_removed_and_keeps_their_history(): void
    {
        $gone = ScProduct::create(['asin' => 'B9', 'sku' => 'SKU-GONE', 'title' => 'Made-up Old Listing', 'status' => 'Active', 'synced_at' => now()->subWeek()]);
        ScListingAudit::create(['product_id' => $gone->id, 'checked_at' => now()->subDay()]);
        $this->fakeReport(
            self::REPORT_HEADER."SKU-A\tB1\tMade-up Listing\tActive\n",
            self::REPORT_HEADER."SKU-A\tB1\tMade-up Listing\tActive\nSKU-GONE\tB9\tMade-up Old Listing\tActive\n",
        );

        $this->actingAs($this->user('sc-listings'))->post(route('tools.sc-listings.sync'))->assertRedirect();

        $this->assertSame('Removed', $gone->fresh()->status);
        $this->assertSame(1, ScListingAudit::where('product_id', $gone->id)->count());
        $this->assertSame(['SKU-A'], ScProduct::active()->pluck('sku')->all());
        $this->actingAs($this->user('sc-listings'))->get(route('tools.sc-listings.index'))->assertDontSee('Made-up Old Listing');

        // Back in a later report, it comes back with Amazon's status.
        $this->actingAs($this->user('sc-listings'))->post(route('tools.sc-listings.sync'));
        $this->assertSame('Active', $gone->fresh()->status);
    }

    /** With the step above, a report without the SKU column would otherwise mark every real SKU removed. */
    public function test_a_report_without_the_seller_sku_column_changes_nothing(): void
    {
        ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'Made-up Listing', 'status' => 'Active', 'synced_at' => now()]);
        $this->fakeReport("sku\tasin1\titem-name\tstatus\nSKU-X\tB2\tMade-up Other\tActive\n");

        $this->actingAs($this->user('sc-listings'))->post(route('tools.sc-listings.sync'))
            ->assertRedirect()->assertSessionHas('error', fn ($message) => str_contains($message, 'seller-sku'));

        $this->assertSame(['SKU-A' => 'Active'], ScProduct::pluck('status', 'sku')->all());
    }

    /**
     * Found issue 16 (Lesley, 2026-12-06): rejected submissions stay in the
     * history, marked as such. The page used to say only accepted changes
     * were logged.
     */
    public function test_the_history_says_every_submission_is_logged_and_marks_rejections(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => ['item_name' => [['value' => 'Old Title']]], 'productType' => 'DRINK_FLAVORED']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['images' => [['images' => []]]]),
            'sp-api.test/definitions/2020-09-01/productTypes/*' => Http::response(['schema' => ['link' => ['resource' => 'https://schema.test/doc']]]),
            'schema.test/*' => Http::response(['properties' => []]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Made-up Drink', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingEdit::create(['asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'item_name', 'old_value' => '[]', 'new_value' => '[]', 'status' => 'REJECTED']);

        $this->actingAs($this->user('vc-listings'))->get(route('tools.vc-listings.index', ['asin' => 'B1', 'ajax' => 1]))
            ->assertOk()
            ->assertSee("Every submission sent to Amazon, with Amazon's answer.")
            ->assertSee('REJECTED')
            ->assertDontSee('Every accepted change is logged');
    }

    public function test_both_tools_and_the_daily_audit_are_registered_once(): void
    {
        foreach (['vc-listings', 'sc-listings'] as $slug) {
            $tool = Tool::where('url', "/tools/{$slug}")->sole();
            $this->assertSame($slug, $tool->slug);
            $this->assertSame('Amazon · Listings', $tool->group);
            $this->assertTrue(Permission::where('name', "tool.{$slug}")->exists());
        }

        $task = DB::table('scheduled_tasks')->where('command', 'listings:audit-run')->sole();
        $this->assertSame('30 8 * * *', $task->cron_expression); // after the old app's 08:00 run until cutover
        $this->assertNull($task->timezone);

        (require database_path('migrations/2026_12_06_000002_register_listings_editors.php'))->up();
        $this->assertSame(1, Tool::where('slug', 'vc-listings')->count());
        $this->assertSame(1, DB::table('scheduled_tasks')->where('command', 'listings:audit-run')->count());
    }
}
