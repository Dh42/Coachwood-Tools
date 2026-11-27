<?php

namespace Tests\Unit\Services\Stockout;

use App\Services\Stockout\CatalogEligibilityResolver;
use App\Services\Stockout\StockoutIntervalDerivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class StockoutIntervalDerivationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedDaily(string $title, array $days): void
    {
        $rows = array_map(fn (string $day) => [
            'product_title' => $title,
            'day' => $day,
            'ending_inventory_units' => -1,
            'ingested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $days);

        DB::table('inventory_history_daily')->insert($rows);
    }

    private function service(array $eligibility): StockoutIntervalDerivationService
    {
        $catalog = Mockery::mock(CatalogEligibilityResolver::class);
        $catalog->shouldReceive('resolve')->andReturn($eligibility);

        return new StockoutIntervalDerivationService($catalog);
    }

    public function test_consecutive_days_merge_into_a_single_interval(): void
    {
        $this->seedDaily('BIOSTEEL HOODIE', ['2026-01-01', '2026-01-02', '2026-01-03']);

        $result = $this->service([
            'biosteel hoodie' => ['title' => 'BIOSTEEL HOODIE', 'variants' => [['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU-1']]],
        ])->derive();

        $this->assertSame(1, $result['intervals_written']);
        $this->assertDatabaseCount('variant_availability_intervals', 1);
        $this->assertDatabaseHas('variant_availability_intervals', [
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'sku' => 'SKU-1',
            'started_at' => '2026-01-01 00:00:00',
            'ended_at' => '2026-01-03 23:59:59',
            'source' => 'reconstructed',
            'confidence' => 'day_level_single_variant',
        ]);
    }

    public function test_a_gap_day_splits_into_two_intervals(): void
    {
        $this->seedDaily('BIOSTEEL HOODIE', ['2026-01-01', '2026-01-02', '2026-01-10']);

        $result = $this->service([
            'biosteel hoodie' => ['title' => 'BIOSTEEL HOODIE', 'variants' => [['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU-1']]],
        ])->derive();

        $this->assertSame(2, $result['intervals_written']);
        $this->assertDatabaseHas('variant_availability_intervals', ['started_at' => '2026-01-01 00:00:00', 'ended_at' => '2026-01-02 23:59:59']);
        $this->assertDatabaseHas('variant_availability_intervals', ['started_at' => '2026-01-10 00:00:00', 'ended_at' => '2026-01-10 23:59:59']);
    }

    public function test_a_multi_variant_product_writes_one_row_per_variant_with_lower_confidence_and_a_note(): void
    {
        $this->seedDaily('BIOSTEEL HOODIE', ['2026-01-01']);

        $result = $this->service([
            'biosteel hoodie' => ['title' => 'BIOSTEEL HOODIE', 'variants' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU-1'],
                ['id' => 'gid://shopify/ProductVariant/2', 'sku' => 'SKU-2'],
            ]],
        ])->derive();

        $this->assertSame(2, $result['intervals_written']);
        $this->assertDatabaseHas('variant_availability_intervals', ['variant_id' => 'gid://shopify/ProductVariant/1', 'confidence' => 'day_level_multi_variant']);
        $this->assertDatabaseHas('variant_availability_intervals', ['variant_id' => 'gid://shopify/ProductVariant/2', 'confidence' => 'day_level_multi_variant']);

        $note = DB::table('variant_availability_intervals')->value('notes');
        $this->assertStringContainsString('2 eligible variant', $note);
    }

    public function test_a_product_with_no_eligible_variant_is_skipped_and_counted(): void
    {
        $this->seedDaily('DISCONTINUED THING', ['2026-01-01']);

        $result = $this->service([])->derive();

        $this->assertSame(0, $result['intervals_written']);
        $this->assertSame(1, $result['products_skipped']);
        $this->assertDatabaseCount('variant_availability_intervals', 0);
    }

    public function test_re_running_replaces_reconstructed_rows_rather_than_duplicating(): void
    {
        $this->seedDaily('BIOSTEEL HOODIE', ['2026-01-01']);

        $eligibility = ['biosteel hoodie' => ['title' => 'BIOSTEEL HOODIE', 'variants' => [['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SKU-1']]]];
        $this->service($eligibility)->derive();
        $this->service($eligibility)->derive();

        $this->assertDatabaseCount('variant_availability_intervals', 1);
    }

    public function test_a_snapshot_sourced_row_is_untouched_by_a_rerun(): void
    {
        DB::table('variant_availability_intervals')->insert([
            'variant_id' => 'gid://shopify/ProductVariant/99',
            'product_title' => null,
            'started_at' => now(),
            'ended_at' => null,
            'source' => 'snapshot',
            'confidence' => 'exact',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service([])->derive();

        $this->assertDatabaseHas('variant_availability_intervals', ['variant_id' => 'gid://shopify/ProductVariant/99', 'source' => 'snapshot']);
    }
}
