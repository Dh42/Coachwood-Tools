<?php

namespace Tests\Feature\Legacy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `legacy:import-lost-sales` and `compare:lost-sales` against a made-up old
 * database (the in-memory `legacy` connection). Every value is invented.
 */
class ImportLostSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (['lost_sales_estimates', 'variant_availability_intervals', 'sales_history_daily', 'inventory_history_daily', 'inventory_snapshots'] as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('inventory_snapshots', function (Blueprint $t) {
            $t->id();
            $t->string('inventory_item_id');
            $t->string('variant_id')->nullable();
            $t->string('location_id');
            $t->string('state');
            $t->integer('quantity');
            $t->timestamp('captured_at');
            $t->string('source');
            $t->timestamps();
        });
        $schema->create('inventory_history_daily', function (Blueprint $t) {
            $t->id();
            $t->string('product_title');
            $t->date('day');
            $t->integer('ending_inventory_units');
            $t->timestamp('ingested_at');
            $t->timestamps();
        });
        $schema->create('sales_history_daily', function (Blueprint $t) {
            $t->id();
            $t->string('product_title');
            $t->date('day');
            $t->integer('net_items_sold');
            $t->decimal('net_sales', 12, 2);
            $t->timestamp('ingested_at');
            $t->timestamps();
        });
        $schema->create('variant_availability_intervals', function (Blueprint $t) {
            $t->id();
            $t->string('variant_id');
            $t->string('sku')->nullable();
            $t->string('product_title')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->string('source');
            $t->string('confidence');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        $schema->create('lost_sales_estimates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('variant_availability_interval_id');
            $t->string('variant_id');
            $t->string('sku')->nullable();
            $t->string('product_title')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->decimal('days_out_of_stock', 8, 2);
            $t->decimal('baseline_units_per_day', 10, 2);
            $t->decimal('baseline_revenue_per_day', 12, 2);
            $t->unsignedInteger('baseline_window_days_used');
            $t->string('baseline_confidence');
            $t->text('substitution_note')->nullable();
            $t->decimal('gross_units_lost', 10, 2);
            $t->decimal('gross_revenue_lost', 12, 2);
            $t->string('model_version');
            $t->timestamp('computed_at');
            $t->timestamps();
        });

        $old = DB::connection('legacy');
        $old->table('inventory_snapshots')->insert(['inventory_item_id' => 'gid://shopify/InventoryItem/1', 'location_id' => 'gid://shopify/Location/1', 'state' => 'available', 'quantity' => 3, 'captured_at' => '2026-11-01 00:00:00', 'source' => 'cron']);
        $old->table('inventory_history_daily')->insert([
            ['id' => 70, 'product_title' => 'Made-up Mix', 'day' => '2026-01-01', 'ending_inventory_units' => 0, 'ingested_at' => '2026-09-27 00:00:00'],
            ['id' => 71, 'product_title' => 'Made-up Mix', 'day' => '2026-01-02', 'ending_inventory_units' => 12, 'ingested_at' => '2026-09-27 00:00:00'],
        ]);
        $old->table('sales_history_daily')->insert([
            ['id' => 80, 'product_title' => 'Made-up Mix', 'day' => '2026-01-02', 'net_items_sold' => 4, 'net_sales' => 99.5, 'ingested_at' => '2026-09-28 00:00:00'],
        ]);
        $old->table('variant_availability_intervals')->insert([
            ['id' => 90, 'variant_id' => 'gid://shopify/ProductVariant/9', 'sku' => 'MADE-UP', 'product_title' => 'Made-up Mix', 'started_at' => '2026-01-01 00:00:00', 'ended_at' => '2026-01-01 23:59:59', 'source' => 'reconstructed', 'confidence' => 'day'],
        ]);
        $old->table('lost_sales_estimates')->insert([
            ['id' => 95, 'variant_availability_interval_id' => 90, 'variant_id' => 'gid://shopify/ProductVariant/9', 'sku' => 'MADE-UP', 'product_title' => 'Made-up Mix', 'started_at' => '2026-01-01 00:00:00', 'ended_at' => '2026-01-01 23:59:59', 'days_out_of_stock' => 1, 'baseline_units_per_day' => 4, 'baseline_revenue_per_day' => 99.5, 'baseline_window_days_used' => 3, 'baseline_confidence' => 'normal', 'gross_units_lost' => 4, 'gross_revenue_lost' => 99.5, 'model_version' => 'v1-trailing-baseline', 'computed_at' => '2026-09-28 03:49:13'],
        ]);
    }

    public function test_it_copies_history_intervals_and_estimates_but_not_snapshots(): void
    {
        $this->artisan('legacy:import-lost-sales')->assertSuccessful();

        $this->assertSame(12, DB::table('inventory_history_daily')->where('product_title', 'Made-up Mix')->where('day', '2026-01-02')->value('ending_inventory_units'));
        $this->assertSame(1, DB::table('sales_history_daily')->count());
        $this->assertSame(90, DB::table('lost_sales_estimates')->where('id', 95)->value('variant_availability_interval_id'));
        $this->assertSame(1, DB::table('variant_availability_intervals')->where('id', 90)->count());
        $this->assertSame(0, DB::table('inventory_snapshots')->count());
    }

    public function test_history_is_matched_on_product_and_day_and_the_old_app_wins(): void
    {
        // This app pulled the same day itself, with a different ID and value.
        DB::table('inventory_history_daily')->insert(['id' => 5, 'product_title' => 'Made-up Mix', 'day' => '2026-01-02', 'ending_inventory_units' => 999, 'ingested_at' => '2026-11-01 00:00:00']);

        $this->artisan('legacy:import-lost-sales')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['inventory_history_daily', 2, 1, 1, 0],
                ['sales_history_daily', 1, 1, 0, 0],
                ['variant_availability_intervals', 1, 1, 0, 0],
                ['lost_sales_estimates', 1, 1, 0, 0],
            ])
            ->assertSuccessful();

        $this->assertSame(2, DB::table('inventory_history_daily')->count());
        $this->assertSame(12, DB::table('inventory_history_daily')->where('id', 5)->value('ending_inventory_units'));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('legacy:import-lost-sales')->assertSuccessful();

        $this->artisan('legacy:import-lost-sales')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['inventory_history_daily', 2, 0, 2, 0],
                ['sales_history_daily', 1, 0, 1, 0],
                ['variant_availability_intervals', 1, 0, 1, 0],
                ['lost_sales_estimates', 1, 0, 1, 0],
            ])
            ->assertSuccessful();
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('legacy:import-lost-sales', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('lost_sales_estimates')->count());
        $this->assertSame(0, DB::table('inventory_history_daily')->count());
    }

    public function test_after_the_import_the_two_apps_match(): void
    {
        DB::table('inventory_history_daily')->insert(['id' => 5, 'product_title' => 'Made-up Mix', 'day' => '2026-01-02', 'ending_inventory_units' => 999, 'ingested_at' => '2026-11-01 00:00:00']);
        $this->artisan('legacy:import-lost-sales')->assertSuccessful();

        $this->artisan('compare:lost-sales')
            ->expectsOutputToContain('Everything matches.')
            ->assertSuccessful();
    }

    public function test_a_difference_is_reported_without_printing_values(): void
    {
        $this->artisan('legacy:import-lost-sales')->assertSuccessful();
        DB::table('lost_sales_estimates')->where('id', 95)->update(['product_title' => 'Edited here']);

        $this->artisan('compare:lost-sales')
            ->expectsOutputToContain('1 section(s) differ.')
            ->doesntExpectOutputToContain('Edited here')
            ->doesntExpectOutputToContain('Made-up')
            ->assertFailed();
    }
}
