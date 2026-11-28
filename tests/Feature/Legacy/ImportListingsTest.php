<?php

namespace Tests\Feature\Legacy;

use App\Models\ListingAudit;
use App\Models\ListingAuditAttributeCatalog;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Models\ScListingAudit;
use App\Models\ScProduct;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `legacy:import-listings` and `compare:listings` against a made-up old
 * database (the in-memory `legacy` connection), and the `sc_products` key
 * clash in `legacy:import-products`. Every value is invented.
 */
class ImportListingsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (['', 'sc_'] as $prefix) {
            foreach (['listing_audit_attribute_catalog', 'listing_audit_config', 'listing_audit', 'listing_edits'] as $table) {
                $schema->dropIfExists($prefix.$table);
            }
            $schema->create($prefix.'listing_edits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('product_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('asin');
                $t->string('sku');
                $t->string('attribute');
                $t->text('old_value')->nullable();
                $t->text('new_value');
                $t->string('submission_id')->nullable();
                $t->string('status');
                $t->text('issues_json')->nullable();
                $t->timestamp('created_at')->nullable();
            });
            $schema->create($prefix.'listing_audit', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('product_id');
                foreach (['has_serving_quantity', 'has_energy_content', 'has_total_fat', 'has_total_carbohydrate', 'has_protein', 'has_regulatory_compliance'] as $flag) {
                    $t->boolean($flag)->default(false);
                }
                $t->unsignedTinyInteger('bullet_point_count')->default(0);
                foreach (['brand_code', 'manufacturer_code', 'replenishment_category', 'aplus_status', 'contribution_mismatch_attrs', 'fetch_error'] as $column) {
                    $t->string($column)->nullable();
                }
                $t->unsignedTinyInteger('image_count')->nullable();
                $t->unsignedTinyInteger('contribution_mismatch_count')->nullable();
                foreach (['audited_attributes', 'listing_issues', 'listing_restrictions'] as $column) {
                    $t->text($column)->nullable();
                }
                $t->dateTime('checked_at');
            });
            $schema->create($prefix.'listing_audit_config', function (Blueprint $t) {
                $t->id();
                $t->dateTime('effective_from');
                for ($i = 1; $i <= 7; $i++) {
                    $t->string("attr_slot_{$i}")->nullable();
                    $t->string("alias_slot_{$i}")->nullable();
                }
                $t->string('reason')->nullable();
                $t->unsignedBigInteger('changed_by_user_id')->nullable();
            });
            $schema->create($prefix.'listing_audit_attribute_catalog', function (Blueprint $t) {
                $t->string('attribute_key')->primary();
                $t->dateTime('first_seen_at');
                $t->dateTime('last_seen_at');
            });
        }

        // The products are already here (legacy:import-products).
        $vc = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Made-up Drink', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        $sc = ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-US-1', 'title' => 'Made-up Drink US', 'status' => 'Active', 'synced_at' => '2026-11-01 00:00:00']);

        $old = DB::connection('legacy');
        $old->table('listing_edits')->insert([
            ['id' => 1, 'product_id' => $vc->id, 'user_id' => null, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'item_name', 'old_value' => '[{"value":"Made-up old title"}]', 'new_value' => '[{"value":"Made-up new title, which is longer"}]', 'submission_id' => 'sub-1', 'status' => 'ACCEPTED', 'issues_json' => '[]', 'created_at' => '2026-10-01 10:00:00'],
        ]);
        // One insert per row: their columns differ.
        $old->table('listing_audit')->insert(['id' => 1, 'product_id' => $vc->id, 'bullet_point_count' => 4, 'aplus_status' => 'full', 'image_count' => 6, 'contribution_mismatch_count' => 0, 'audited_attributes' => '{"bullet_point":{"kind":"count","value":4}}', 'listing_issues' => '[]', 'checked_at' => '2026-11-20 08:00:00']);
        $old->table('listing_audit')->insert(['id' => 2, 'product_id' => $vc->id, 'bullet_point_count' => 5, 'aplus_status' => 'full', 'image_count' => 7, 'contribution_mismatch_count' => 1, 'contribution_mismatch_attrs' => 'item_name', 'audited_attributes' => '{"bullet_point":{"kind":"count","value":5}}', 'listing_issues' => '[{"severity":"ERROR","message":"Made-up issue"}]', 'checked_at' => '2026-11-21 08:00:00']);
        $old->table('listing_audit_config')->insert([
            ['id' => 1, 'effective_from' => '2026-09-01 00:00:00', 'attr_slot_1' => 'bullet_point', 'alias_slot_1' => 'Bullets', 'reason' => 'Made-up reason', 'changed_by_user_id' => null],
        ]);
        $old->table('listing_audit_attribute_catalog')->insert([
            ['attribute_key' => 'bullet_point', 'first_seen_at' => '2026-08-20 00:00:00', 'last_seen_at' => '2026-11-21 08:00:00'],
            ['attribute_key' => 'item_name', 'first_seen_at' => '2026-08-20 00:00:00', 'last_seen_at' => '2026-11-21 08:00:00'],
        ]);
        $old->table('sc_listing_audit')->insert([
            ['id' => 1, 'product_id' => $sc->id, 'bullet_point_count' => 3, 'aplus_status' => 'none', 'checked_at' => '2026-11-21 08:01:00'],
        ]);
        $old->table('sc_listing_audit_attribute_catalog')->insert([
            ['attribute_key' => 'item_name', 'first_seen_at' => '2026-08-20 00:00:00', 'last_seen_at' => '2026-11-21 08:01:00'],
        ]);
    }

    public function test_it_mirrors_the_eight_tables_and_a_rerun_changes_nothing(): void
    {
        $this->artisan('legacy:import-listings', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, ListingAudit::count());

        $first = [
            ['listing_audit_config', 1, 1, 0, 0], ['listing_edits', 1, 1, 0, 0], ['listing_audit', 2, 2, 0, 0],
            ['sc_listing_audit_config', 0, 0, 0, 0], ['sc_listing_edits', 0, 0, 0, 0], ['sc_listing_audit', 1, 1, 0, 0],
            ['listing_audit_attribute_catalog', 2, 2, 0, 0], ['sc_listing_audit_attribute_catalog', 1, 1, 0, 0],
        ];
        $this->artisan('legacy:import-listings')->expectsTable(self::HEADERS, $first)->assertSuccessful();

        $product = Product::firstOrFail();
        $this->assertSame(2, $product->latestListingAudit->id);
        $this->assertSame(['bullet_point' => ['kind' => 'count', 'value' => 5]], $product->latestListingAudit->audited_attributes);
        $this->assertSame('item_name', ListingEdit::firstOrFail()->attribute);
        $this->assertSame('none', ScProduct::firstOrFail()->latestListingAudit->aplus_status);

        $this->artisan('legacy:import-listings')->expectsTable(self::HEADERS, array_map(
            fn (array $row) => [$row[0], $row[1], 0, $row[1], 0],
            $first,
        ))->assertSuccessful();
    }

    public function test_this_apps_own_audits_and_catalog_keys_give_way_to_the_old_apps(): void
    {
        // This app's daily audit ran first: one audit at an ID the old app also uses, one beyond it, and a new key.
        $product = Product::firstOrFail();
        ListingAudit::create(['id' => 2, 'product_id' => $product->id, 'aplus_status' => 'none', 'checked_at' => '2026-11-22 08:00:00']);
        ListingAudit::create(['id' => 3, 'product_id' => $product->id, 'aplus_status' => 'none', 'checked_at' => '2026-11-22 08:00:00']);
        ListingAuditAttributeCatalog::create(['attribute_key' => 'made_up_key', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('legacy:import-listings')->assertSuccessful();

        $this->assertSame([1, 2], ListingAudit::orderBy('id')->pluck('id')->all());
        $this->assertSame('full', ListingAudit::find(2)->aplus_status);
        $this->assertSame(['bullet_point', 'item_name'], ListingAuditAttributeCatalog::orderBy('attribute_key')->pluck('attribute_key')->all());
    }

    public function test_after_the_import_the_two_apps_match_and_a_difference_prints_no_values(): void
    {
        $this->artisan('legacy:import-listings')->assertSuccessful();
        $this->artisan('compare:listings')->expectsOutputToContain('Everything matches.')->assertSuccessful();

        // A change after the 19th character of a text value is seen (found issue 34).
        DB::table('listing_edits')->where('id', 1)->update(['new_value' => '[{"value":"Made-up new title, which is shorter"}]']);
        ScListingAudit::query()->update(['aplus_status' => 'full']);

        $this->artisan('compare:listings')
            ->expectsOutputToContain('3 section(s) differ.')
            ->doesntExpectOutputToContain('Made-up')
            ->assertFailed();
    }

    /** The SC sync here can add a SKU the old app later adds under another ID. */
    public function test_the_products_import_replaces_a_sku_this_app_synced_under_another_id(): void
    {
        $legacy = Schema::connection('legacy');
        foreach (['products', 'sc_products'] as $table) {
            $legacy->dropIfExists($table);
        }
        $legacy->create('products', function (Blueprint $t) {
            $t->id();
            $t->string('asin')->unique();
            $t->string('sku')->nullable();
            $t->string('title');
            $t->string('type');
            $t->decimal('list_price', 10, 2)->nullable();
            $t->decimal('cost', 10, 2)->nullable();
            $t->decimal('cogs', 10, 2)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $legacy->create('sc_products', function (Blueprint $t) {
            $t->id();
            $t->string('asin');
            $t->string('sku')->unique();
            $t->string('title');
            $t->string('status')->default('Active');
            $t->dateTime('synced_at');
            $t->timestamp('created_at')->nullable();
        });
        $vcId = Product::firstOrFail()->id;
        DB::connection('legacy')->table('products')->insert(['id' => $vcId, 'asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Made-up Drink', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        DB::connection('legacy')->table('sc_products')->insert([
            ['id' => 1, 'asin' => 'B1', 'sku' => 'SKU-US-1', 'title' => 'Made-up Drink US', 'status' => 'Active', 'synced_at' => '2026-11-01 00:00:00', 'created_at' => '2026-11-01 00:00:00'],
            ['id' => 7, 'asin' => 'B2', 'sku' => 'SKU-US-NEW', 'title' => 'Made-up New', 'status' => 'Active', 'synced_at' => '2026-11-02 00:00:00', 'created_at' => '2026-11-02 00:00:00'],
        ]);
        // This app's Sync Products saw the new SKU first, at ID 2.
        ScProduct::create(['id' => 2, 'asin' => 'B2', 'sku' => 'SKU-US-NEW', 'title' => 'Made-up New', 'status' => 'Active', 'synced_at' => '2026-11-02 00:00:00']);

        $this->artisan('legacy:import-products')->assertSuccessful();

        $this->assertSame([1 => 'SKU-US-1', 7 => 'SKU-US-NEW'], ScProduct::orderBy('id')->pluck('sku', 'id')->all());
    }
}
