<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockoutPruneSnapshotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedSnapshots(int $count, int $daysAgo): void
    {
        $at = now()->subDays($daysAgo);
        $rows = array_fill(0, $count, [
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'location_id' => 'gid://shopify/Location/100',
            'state' => 'available', 'quantity' => 1, 'source' => 'cron',
            'captured_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('inventory_snapshots')->insert($chunk);
        }
    }

    public function test_it_deletes_only_snapshots_older_than_the_retention_window(): void
    {
        config(['stockout.snapshot_retention_days' => 30]);
        $this->seedSnapshots(25, 45);
        $this->seedSnapshots(10, 5);

        $this->artisan('stockout:prune-snapshots', ['--batch' => 7, '--pause-ms' => 0])
            ->expectsOutputToContain('Deleted 25 snapshot(s) older than 30 days in 4 batch(es).')
            ->assertSuccessful();

        $this->assertDatabaseCount('inventory_snapshots', 10);
    }

    public function test_a_run_stops_at_max_batches_and_the_next_run_carries_on(): void
    {
        $this->seedSnapshots(20, 200);

        $this->artisan('stockout:prune-snapshots', ['--days' => 90, '--batch' => 5, '--max-batches' => 2, '--pause-ms' => 0])
            ->expectsOutputToContain('Stopped at --max-batches')
            ->assertSuccessful();
        $this->assertDatabaseCount('inventory_snapshots', 10);

        $this->artisan('stockout:prune-snapshots', ['--days' => 90, '--batch' => 5, '--pause-ms' => 0])->assertSuccessful();
        $this->assertDatabaseCount('inventory_snapshots', 0);
    }

    public function test_it_refuses_a_zero_day_window(): void
    {
        config(['stockout.snapshot_retention_days' => 0]);
        $this->seedSnapshots(3, 200);

        $this->artisan('stockout:prune-snapshots', ['--pause-ms' => 0])->assertFailed();

        $this->assertDatabaseCount('inventory_snapshots', 3);
    }

    public function test_the_incident_indexes_exist_after_migrating(): void
    {
        $this->assertTrue(Schema::hasIndex('inventory_snapshots', ['inventory_item_id', 'captured_at', 'variant_id']));
        // Changed in the rebuild: the asp_ingest_log half of this test is in
        // AspTrackerSafetyTest, with the table it checks.
    }

    // Changed in the rebuild (decision 2026-12-01): the old app registered
    // the clean-up disabled and never turned it on, so the table grew without
    // limit. Here it is enabled wherever the sweep is.
    public function test_the_prune_task_is_registered_enabled_like_the_sweep(): void
    {
        $this->assertDatabaseHas('scheduled_tasks', ['command' => 'stockout:snapshot-inventory', 'enabled' => true]);
        $this->assertDatabaseHas('scheduled_tasks', ['command' => 'stockout:prune-snapshots', 'enabled' => true]);
    }
}
