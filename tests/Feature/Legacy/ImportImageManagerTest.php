<?php

namespace Tests\Feature\Legacy;

use App\Models\ImageAudit;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `legacy:import-image-manager` and `compare:image-manager` against a
 * made-up old database (the in-memory `legacy` connection). Every value is
 * invented.
 */
class ImportImageManagerTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'];

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (['image_audits', 'listing_edits'] as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('image_audits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('unattributed_variants')->nullable();
            $t->unsignedTinyInteger('unattributed_count')->nullable();
            $t->string('not_live_variants')->nullable();
            $t->unsignedTinyInteger('not_live_count')->nullable();
            $t->string('fetch_error')->nullable();
            $t->dateTime('checked_at');
        });
        $schema->create('listing_edits', function (Blueprint $t) {
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

        // The products are already here (legacy:import-products).
        $this->productId = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Made-up Drink', 'type' => 'DRINK_FLAVORED', 'active' => true])->id;

        $old = DB::connection('legacy');
        $old->table('image_audits')->insert([
            ['id' => 1, 'product_id' => $this->productId, 'unattributed_variants' => 'MAIN,PT02', 'unattributed_count' => 2, 'not_live_variants' => null, 'not_live_count' => 0, 'fetch_error' => null, 'checked_at' => '2026-11-20 09:00:00'],
            ['id' => 2, 'product_id' => $this->productId, 'unattributed_variants' => 'MAIN', 'unattributed_count' => 1, 'not_live_variants' => 'PT01', 'not_live_count' => 1, 'fetch_error' => null, 'checked_at' => '2026-11-21 09:00:00'],
        ]);
        $old->table('listing_edits')->insert([
            ['id' => 1, 'product_id' => $this->productId, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'other_product_image_locator_1', 'old_value' => '[]', 'new_value' => '[{"media_location":"https://tools.example/storage/vc-image-manager/B1/made-up.jpg"}]', 'status' => 'ACCEPTED', 'created_at' => '2026-11-01 10:00:00'],
            ['id' => 2, 'product_id' => $this->productId, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'item_name', 'old_value' => '[]', 'new_value' => '[{"value":"Made-up title"}]', 'status' => 'ACCEPTED', 'created_at' => '2026-11-02 10:00:00'],
        ]);
    }

    public function test_it_mirrors_the_audits_and_a_rerun_changes_nothing(): void
    {
        $this->artisan('legacy:import-image-manager', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, ImageAudit::count());

        // This app's own audit, at an ID beyond the old app's, gives way.
        DB::table('image_audits')->insert(['id' => 3, 'product_id' => $this->productId, 'unattributed_count' => 0, 'checked_at' => '2026-11-22 09:00:00']);

        $this->artisan('legacy:import-image-manager')->expectsTable(self::HEADERS, [['image_audits', 2, 2, 0, 1]])->assertSuccessful();
        $this->assertSame(2, Product::findOrFail($this->productId)->latestImageAudit->id);

        $this->artisan('legacy:import-image-manager')->expectsTable(self::HEADERS, [['image_audits', 2, 0, 2, 0]])->assertSuccessful();
    }

    public function test_the_compare_matches_after_the_import_and_counts_missing_image_files(): void
    {
        Storage::fake('public');
        $this->artisan('legacy:import-image-manager')->assertSuccessful();

        // The audits match, but the one submitted image file isn't on this app's disk yet.
        $this->artisan('compare:image-manager')
            ->expectsOutputToContain('1 section(s) differ.')
            ->doesntExpectOutputToContain('made-up.jpg')
            ->assertFailed();

        Storage::disk('public')->put('vc-image-manager/B1/made-up.jpg', 'made-up image bytes');
        $this->artisan('compare:image-manager')->expectsOutputToContain('Everything matches.')->assertSuccessful();

        ImageAudit::whereKey(2)->update(['not_live_variants' => null, 'not_live_count' => 0]);
        $this->artisan('compare:image-manager')->expectsOutputToContain('2 section(s) differ.')->assertFailed();
    }
}
