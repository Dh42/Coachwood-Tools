<?php

namespace Tests\Feature\Legacy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AspOrderFixtures;
use Tests\TestCase;

/**
 * `legacy:import-asp-tracker` and `compare:asp-tracker` against a made-up
 * old database (the in-memory `legacy` connection). Every value is invented.
 */
class ImportAspTrackerTest extends TestCase
{
    use AspOrderFixtures;
    use RefreshDatabase;

    private const TABLES = ['asp_sales_lines', 'asp_line_discounts', 'asp_pending_orders', 'asp_ingest_log', 'asp_daily_sales', 'asp_daily_discounts'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAspStore();

        $schema = Schema::connection('legacy');
        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
            // The old app's columns are this app's (same final shape).
            $schema->create($table, function (Blueprint $t) use ($table) {
                foreach (Schema::getColumns($table) as $column) {
                    $column['auto_increment']
                        ? $t->id($column['name'])
                        : $t->addColumn($column['type_name'] === 'integer' ? 'integer' : 'text', $column['name'])->nullable();
                }
            });
        }

        $old = DB::connection('legacy');
        $old->table('asp_sales_lines')->insert([
            $this->line(101, 1001, 'Made-up Whey', 3, 30.00),
            $this->line(102, 1001, 'Made-up Shaker', 1, 0.00, ['is_free_item' => 1]),
            $this->line(201, 1002, 'Made-up Whey', 2, 25.00),
        ]);
        $old->table('asp_line_discounts')->insert([
            ['line_item_id' => 101, 'order_id' => 1001, 'label' => 'MADEUP10', 'label_group' => 'MADEUP10', 'discount_type' => 'code', 'level' => 'order', 'amount' => 6.00],
            ['line_item_id' => 201, 'order_id' => 1002, 'label' => 'Sale', 'label_group' => 'Sale', 'discount_type' => 'automatic', 'level' => 'line', 'amount' => 10.00],
        ]);
        $old->table('asp_pending_orders')->insert(['store' => 'CP', 'order_id' => 1003, 'reason' => 'webhook', 'attempts' => 0, 'created_at' => '2026-11-20 00:00:00', 'updated_at' => '2026-11-20 00:00:00']);
        $old->table('asp_ingest_log')->insert(['id' => 40, 'store' => 'CP', 'kind' => 'backfill', 'status' => 'completed', 'since' => '2024-01-01', 'started_at' => '2026-10-20 00:00:00', 'finished_at' => '2026-10-20 01:00:00', 'orders_touched' => 2, 'lines_changed' => 3, 'unattributed_refunds' => 0]);
    }

    /** @return array<string, mixed> */
    private function line(int $id, int $orderId, string $title, int $qty, float $unit, array $overrides = []): array
    {
        return $overrides + [
            'line_item_id' => $id, 'store' => 'CP', 'order_id' => $orderId, 'order_name' => "#{$orderId}",
            'order_created_at' => '2026-01-15 15:00:00', 'order_date' => '2026-01-15', 'week_start' => '2026-01-12',
            'source_name' => 'web', 'app_name' => 'Online Store', 'product_id' => 7, 'variant_id' => 70 + $id % 10, 'sku' => "MU-{$id}",
            'product_title' => $title, 'variant_title' => 'Made-up', 'quantity' => $qty, 'original_unit_price' => $unit, 'list_unit_price' => $unit,
            'list_price_source' => 'line', 'variant_price' => $unit, 'line_level_discount' => 0, 'order_level_discount' => 0, 'selling_plan_discount' => 0,
            'net_line_total' => $qty * $unit, 'is_subscription' => 0, 'is_free_item' => 0, 'refunded_qty' => 0, 'refunded_amount' => 0,
            'financial_status' => 'paid', 'content_hash' => sha1("made-up-{$id}"),
        ];
    }

    public function test_it_copies_lines_discounts_queue_and_log_then_rebuilds_the_rollups(): void
    {
        $this->artisan('legacy:import-asp-tracker')->expectsOutputToContain('Import complete.')->assertSuccessful();

        $this->assertSame(3, DB::table('asp_sales_lines')->count());
        $this->assertSame(2, DB::table('asp_line_discounts')->count());
        $this->assertSame(1, DB::table('asp_pending_orders')->where('order_id', 1003)->count());
        $this->assertSame('completed', DB::table('asp_ingest_log')->where('id', 40)->value('status'));
        $this->assertGreaterThan(0, DB::table('asp_daily_sales')->where('store', 'CP')->count());
    }

    public function test_each_imported_order_ends_up_exactly_as_in_the_old_app(): void
    {
        // This app's catch-up saw order 1001 with a line the old app no longer has,
        // a wrong discount on it, and an order the old app hasn't seen yet.
        DB::table('asp_sales_lines')->insert([
            $this->line(109, 1001, 'Made-up Removed', 1, 5.00),
            $this->line(301, 1009, 'Made-up New Order', 1, 40.00),
        ]);
        DB::table('asp_line_discounts')->insert(['line_item_id' => 101, 'order_id' => 1001, 'label' => 'WRONG', 'label_group' => 'WRONG', 'discount_type' => 'code', 'level' => 'order', 'amount' => 1.00]);

        $this->artisan('legacy:import-asp-tracker')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['asp_sales_lines', 3, 3, 0, 1],
                ['asp_line_discounts', 2, 2, 0, 1],
                ['asp_pending_orders', 1, 1, 0, 0],
                ['asp_ingest_log', 1, 1, 0, 0],
            ])
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([101, 102, 201, 301], DB::table('asp_sales_lines')->pluck('line_item_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['MADEUP10'], DB::table('asp_line_discounts')->where('order_id', 1001)->pluck('label')->all());
    }

    public function test_running_it_again_changes_nothing_and_a_dry_run_writes_nothing(): void
    {
        $this->artisan('legacy:import-asp-tracker', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, DB::table('asp_sales_lines')->count());
        $this->assertSame(0, DB::table('asp_ingest_log')->count());

        $this->artisan('legacy:import-asp-tracker')->assertSuccessful();
        $this->artisan('legacy:import-asp-tracker')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['asp_sales_lines', 3, 0, 3, 0],
                ['asp_line_discounts', 2, 0, 0, 0],
                ['asp_pending_orders', 1, 0, 1, 0],
                ['asp_ingest_log', 1, 0, 1, 0],
            ])
            ->assertSuccessful();
    }

    public function test_after_the_import_the_two_apps_match_and_a_difference_prints_no_values(): void
    {
        $this->artisan('legacy:import-asp-tracker')->assertSuccessful();
        // The old app's own nightly rebuild produces the same rollups.
        foreach (['asp_daily_sales', 'asp_daily_discounts'] as $table) {
            DB::connection('legacy')->table($table)->insert(DB::table($table)->get()->map(fn ($row) => (array) $row)->all());
        }

        $this->artisan('compare:asp-tracker')->expectsOutputToContain('Everything matches.')->assertSuccessful();

        DB::table('asp_sales_lines')->where('line_item_id', 201)->update(['product_title' => 'Edited here']);
        $this->artisan('compare:asp-tracker')
            ->expectsOutputToContain('1 section(s) differ.')
            ->doesntExpectOutputToContain('Edited here')
            ->doesntExpectOutputToContain('Made-up')
            ->assertFailed();
    }
}
