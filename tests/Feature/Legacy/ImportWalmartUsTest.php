<?php

namespace Tests\Feature\Legacy;

use App\Legacy\WalmartUsComparison;
use App\Models\WalmartUsCatalogScore;
use App\Models\WalmartUsListingAudit;
use App\Models\WalmartUsListingAuditIssue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `legacy:import-walmart-us` and `compare:walmart-us` against a made-up old
 * database (the in-memory `legacy` connection). Every value is invented.
 */
class ImportWalmartUsTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_TABLES = ['walmart_us_listing_audit_issues', 'walmart_us_listing_audits', 'walmart_us_listing_snapshot', 'walmart_us_items', 'walmart_us_catalog_scores', 'walmart_us_audit_params'];

    private const SCORES = ['listing_quality', 'content_score', 'offer_score', 'rating_review_score', 'price_score', 'shipping_score', 'transactibility_score', 'defect_ratio'];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (self::OLD_TABLES as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('walmart_us_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            foreach (['wpid', 'gtin', 'product_name', 'publish_status', 'lifecycle_status', 'internal_sku'] as $c) {
                $t->string($c)->nullable();
            }
            $t->decimal('price', 10, 2)->nullable();
            $t->dateTime('last_synced_at')->nullable();
        });
        $schema->create('walmart_us_listing_snapshot', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
            $t->string('wpid')->nullable();
            $t->string('content_hash');
            $t->longText('item_json');
            $t->longText('listing_quality_json')->nullable();
            $t->dateTime('captured_at');
        });
        $schema->create('walmart_us_listing_audits', function (Blueprint $t) {
            $t->id();
            $t->string('sku');
            $t->date('as_of_date');
            $t->dateTime('run_at');
            $t->unsignedBigInteger('snapshot_id')->nullable();
            foreach (self::SCORES as $c) {
                $t->decimal($c, 6, 4)->nullable();
            }
            $t->unsignedInteger('item_defect_cnt')->nullable();
            $t->decimal('our_rule_score', 5, 2)->nullable();
        });
        $schema->create('walmart_us_listing_audit_issues', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('audit_id');
            $t->string('source');
            $t->string('code');
            foreach (['severity', 'field', 'message', 'recommended_action'] as $c) {
                $t->text($c)->nullable();
            }
        });
        $schema->create('walmart_us_catalog_scores', function (Blueprint $t) {
            $t->id();
            $t->dateTime('run_at');
            foreach (self::SCORES as $c) {
                $t->decimal($c, 6, 4)->nullable();
            }
            $t->unsignedInteger('item_defect_cnt')->nullable();
            $t->unsignedInteger('item_count')->nullable();
        });
        $schema->create('walmart_us_audit_params', function (Blueprint $t) {
            $t->id();
            $t->dateTime('effective_from');
            foreach (['min_title_length', 'max_title_length', 'min_image_count', 'min_image_resolution_px', 'min_description_length', 'min_bullet_count'] as $c) {
                $t->unsignedSmallInteger($c)->nullable();
            }
            $t->decimal('price_guardrail_pct', 5, 4)->nullable();
            foreach (['require_shipping_template', 'exclude_trending_items', 'exclude_wfs_items'] as $c) {
                $t->boolean($c)->default(false);
            }
            $t->string('reason')->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
        });

        $old = DB::connection('legacy');
        $old->table('walmart_us_items')->insert([['sku' => 'SKU-1', 'product_name' => 'Made-up Widget', 'price' => 19.99]]);
        $old->table('walmart_us_listing_snapshot')->insert([['id' => 70, 'sku' => 'SKU-1', 'content_hash' => str_repeat('a', 64), 'item_json' => '{}', 'captured_at' => '2026-10-05 06:10:00']]);
        $old->table('walmart_us_listing_audits')->insert([['id' => 90, 'sku' => 'SKU-1', 'as_of_date' => '2026-10-05', 'run_at' => '2026-10-05 06:10:00', 'snapshot_id' => 70, 'listing_quality' => 72.5]]);
        $old->table('walmart_us_listing_audit_issues')->insert([
            ['audit_id' => 90, 'source' => 'walmart', 'code' => 'MAIN_IMAGE_MISSING', 'field' => 'mainImage'],
            ['audit_id' => 90, 'source' => 'walmart', 'code' => 'SHORT_TITLE', 'field' => 'productName'],
        ]);
        $old->table('walmart_us_catalog_scores')->insert([
            ['run_at' => '2026-10-05 06:15:00', 'listing_quality' => 70.0, 'item_count' => 27],
            ['run_at' => '2026-10-06 06:15:00', 'listing_quality' => 71.0, 'item_count' => 27],
        ]);
        $old->table('walmart_us_audit_params')->insert([['effective_from' => '2026-09-03 12:00:00', 'min_title_length' => 50, 'changed_by' => 999]]);
    }

    public function test_it_copies_every_table_and_re_maps_the_links(): void
    {
        $this->artisan('legacy:import-walmart-us')->assertSuccessful();

        $audit = WalmartUsListingAudit::sole();
        $this->assertNotSame(90, $audit->id);
        $this->assertSame(str_repeat('a', 64), $audit->snapshot->content_hash);
        $this->assertSame(['MAIN_IMAGE_MISSING', 'SHORT_TITLE'], $audit->issues()->orderBy('code')->pluck('code')->all());
        $this->assertSame(2, WalmartUsCatalogScore::count());
        $this->assertSame('Made-up Widget', DB::table('walmart_us_items')->where('sku', 'SKU-1')->value('product_name'));
        $this->assertNull(DB::table('walmart_us_audit_params')->value('changed_by'), 'user 999 does not exist here');
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('legacy:import-walmart-us')->assertSuccessful();

        $this->artisan('legacy:import-walmart-us')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['walmart_us_items', 1, 0, 1, 0],
                ['walmart_us_listing_snapshot', 1, 0, 1, 0],
                ['walmart_us_listing_audits', 1, 0, 1, 0],
                ['walmart_us_listing_audit_issues', 2, 0, 0, 0],
                ['walmart_us_catalog_scores', 2, 0, 2, 0],
                ['walmart_us_audit_params', 1, 0, 1, 0],
            ])
            ->assertSuccessful();

        $this->assertSame(2, WalmartUsListingAuditIssue::count());
    }

    public function test_this_apps_own_audits_are_left_alone(): void
    {
        $mine = WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-10-06', 'run_at' => '2026-10-06 06:20:00', 'listing_quality' => 75]);

        $this->artisan('legacy:import-walmart-us')->assertSuccessful();

        $this->assertSame('75.00', $mine->fresh()->listing_quality);
        $this->assertSame(2, WalmartUsListingAudit::count());
    }

    public function test_after_the_import_both_apps_match_and_this_apps_own_runs_are_counted_apart(): void
    {
        $this->artisan('legacy:import-walmart-us')->assertSuccessful();
        WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-10-06', 'run_at' => '2026-10-06 06:20:00']);

        $this->artisan('compare:walmart-us', ['--from' => '2026-10-01', '--to' => '2026-10-07'])
            ->expectsOutputToContain('Everything matches.')
            ->assertSuccessful();

        $audits = collect((new WalmartUsComparison)->run(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-07')))->firstWhere('section', 'SKU audits');
        $this->assertSame([1, 1, 1, true], [$audits['old_rows'], $audits['new_rows'], $audits['only_here'], $audits['match']]);
    }

    public function test_a_difference_is_reported_without_printing_values(): void
    {
        $this->artisan('legacy:import-walmart-us')->assertSuccessful();
        WalmartUsListingAuditIssue::where('code', 'SHORT_TITLE')->update(['message' => 'Edited here']);

        $this->artisan('compare:walmart-us', ['--from' => '2026-10-01', '--to' => '2026-10-07'])
            ->expectsOutputToContain('1 section(s) differ.')
            ->doesntExpectOutputToContain('Edited here')
            ->doesntExpectOutputToContain('SKU-1')
            ->assertFailed();
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('legacy:import-walmart-us', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, WalmartUsListingAudit::count());
    }
}
