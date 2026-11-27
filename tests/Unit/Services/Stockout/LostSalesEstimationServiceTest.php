<?php

namespace Tests\Unit\Services\Stockout;

use App\Services\Stockout\LostSalesEstimationService;
use App\Services\Stockout\RtdPetSuccessorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class LostSalesEstimationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedSales(string $title, array $daysUnitsRevenue): void
    {
        $rows = [];
        foreach ($daysUnitsRevenue as $day => [$units, $revenue]) {
            $rows[] = [
                'product_title' => $title,
                'day' => $day,
                'net_items_sold' => $units,
                'net_sales' => $revenue,
                'ingested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('sales_history_daily')->insert($rows);
    }

    private function seedOosDay(string $title, string $day): void
    {
        DB::table('inventory_history_daily')->insert([
            'product_title' => $title,
            'day' => $day,
            'ending_inventory_units' => -1,
            'ingested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedInterval(string $variantId, string $title, string $startedAt, ?string $endedAt): void
    {
        DB::table('variant_availability_intervals')->insert([
            'variant_id' => $variantId,
            'product_title' => $title,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'source' => 'reconstructed',
            'confidence' => 'day_level_single_variant',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, string>  $successors */
    private function service(array $successors = []): LostSalesEstimationService
    {
        $resolver = Mockery::mock(RtdPetSuccessorResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($successors);

        return new LostSalesEstimationService($resolver);
    }

    public function test_it_averages_the_three_most_recent_clean_days_when_they_have_enough_units(): void
    {
        $this->seedSales('BIOSTEEL HOODIE', [
            '2026-01-07' => [10, 100],
            '2026-01-08' => [20, 200],
            '2026-01-09' => [30, 300],
        ]);
        $this->seedInterval('gid://shopify/ProductVariant/1', 'BIOSTEEL HOODIE', '2026-01-10 00:00:00', '2026-01-11 23:59:59');

        $result = $this->service()->estimate();

        $this->assertSame(1, $result['estimates_written']);
        $this->assertDatabaseHas('lost_sales_estimates', [
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'baseline_units_per_day' => 20.00, // (10+20+30)/3
            'baseline_revenue_per_day' => 200.00,
            'baseline_window_days_used' => 3,
            'baseline_confidence' => 'normal',
            'days_out_of_stock' => 2.00,
            'gross_units_lost' => 40.00, // 20 * 2 days
        ]);
    }

    public function test_it_skips_oos_days_when_walking_backward_for_clean_days(): void
    {
        $this->seedSales('BIOSTEEL HOODIE', [
            '2026-01-05' => [10, 100],
            '2026-01-06' => [10, 100],
            '2026-01-07' => [10, 100],
        ]);
        // 01-08 and 01-09 are OOS (should be skipped, not counted as clean zero-sales days)
        $this->seedOosDay('BIOSTEEL HOODIE', '2026-01-08');
        $this->seedOosDay('BIOSTEEL HOODIE', '2026-01-09');
        $this->seedInterval('gid://shopify/ProductVariant/1', 'BIOSTEEL HOODIE', '2026-01-10 00:00:00', '2026-01-10 23:59:59');

        $this->service()->estimate();

        // the 3 clean days should be 01-05, 01-06, 01-07 (skipping the OOS 01-08/01-09)
        $this->assertDatabaseHas('lost_sales_estimates', ['baseline_units_per_day' => 10.00, 'baseline_window_days_used' => 3]);
    }

    public function test_it_falls_back_to_a_wider_window_when_the_primary_window_is_too_thin(): void
    {
        // Only 1 unit total across the 3 most recent clean days - too thin
        $sales = [];
        for ($i = 1; $i <= 14; $i++) {
            $day = sprintf('2026-01-%02d', $i);
            $sales[$day] = [$i === 14 ? 1 : 0, $i === 14 ? 10 : 0];
        }
        $this->seedSales('SLOW MOVER', $sales);
        $this->seedInterval('gid://shopify/ProductVariant/2', 'SLOW MOVER', '2026-01-15 00:00:00', '2026-01-15 23:59:59');

        $this->service()->estimate();

        $this->assertDatabaseHas('lost_sales_estimates', ['baseline_window_days_used' => 14, 'baseline_confidence' => 'thin_fallback']);
    }

    public function test_it_never_walks_before_the_actual_sales_data_floor(): void
    {
        // Only one day of sales data exists at all, right before the interval
        $this->seedSales('NEW PRODUCT', ['2026-01-09' => [5, 50]]);
        $this->seedInterval('gid://shopify/ProductVariant/3', 'NEW PRODUCT', '2026-01-10 00:00:00', '2026-01-10 23:59:59');

        $this->service()->estimate();

        // Only 1 clean day exists within the data floor - must not fabricate zero-sales days beyond it
        $this->assertDatabaseHas('lost_sales_estimates', [
            'baseline_window_days_used' => 1,
            'baseline_confidence' => 'insufficient_data',
            'baseline_units_per_day' => 5.00,
        ]);
    }

    public function test_a_product_with_no_sales_data_at_all_is_skipped(): void
    {
        $this->seedInterval('gid://shopify/ProductVariant/4', 'NEVER SOLD', '2026-01-10 00:00:00', '2026-01-10 23:59:59');

        $result = $this->service()->estimate();

        $this->assertSame(0, $result['estimates_written']);
        $this->assertSame(0, $result['skipped_no_baseline']); // no sales_history_daily rows at all -> whole run short-circuits, not per-interval skip

        $this->assertDatabaseCount('lost_sales_estimates', 0);
    }

    public function test_an_ongoing_interval_with_a_null_ended_at_estimates_through_now(): void
    {
        $this->seedSales('BIOSTEEL HOODIE', [now()->subDays(3)->toDateString() => [10, 100]]);
        $this->seedInterval('gid://shopify/ProductVariant/1', 'BIOSTEEL HOODIE', now()->subDays(2)->startOfDay()->toDateTimeString(), null);

        $result = $this->service()->estimate();

        $this->assertSame(1, $result['estimates_written']);
        $estimate = DB::table('lost_sales_estimates')->first();
        $this->assertNull($estimate->ended_at);
        $this->assertEqualsWithDelta(3.0, $estimate->days_out_of_stock, 0.1);
    }

    public function test_re_running_keeps_prior_estimate_rows_rather_than_replacing_them(): void
    {
        $this->seedSales('BIOSTEEL HOODIE', ['2026-01-09' => [10, 100]]);
        $this->seedInterval('gid://shopify/ProductVariant/1', 'BIOSTEEL HOODIE', '2026-01-10 00:00:00', '2026-01-10 23:59:59');

        $service = $this->service();
        $service->estimate();
        $service->estimate();

        $this->assertDatabaseCount('lost_sales_estimates', 2);
    }

    // --- RTD tetra -> PET successor substitution ---

    public function test_a_successor_in_stock_the_entire_interval_fully_substitutes_it(): void
    {
        $this->seedSales('RTD-WF-12', ['2026-01-09' => [10, 100]]);
        // successor never appears in inventory_history_daily at all -> always "in stock"
        // but it must have SOME known day on/before the interval to count as existing
        $this->seedSales('WF-12-PET', ['2026-01-09' => [5, 50]]);
        $this->seedInterval('gid://shopify/ProductVariant/1', 'RTD-WF-12', '2026-01-10 00:00:00', '2026-01-12 23:59:59');

        $result = $this->service(['rtd-wf-12' => 'WF-12-PET'])->estimate();

        $this->assertSame(0, $result['estimates_written']);
        $this->assertSame(1, $result['fully_substituted']);
        $this->assertDatabaseCount('lost_sales_estimates', 0);
    }

    public function test_a_successor_out_of_stock_for_part_of_the_interval_only_counts_that_part(): void
    {
        $this->seedSales('RTD-WF-12', ['2026-01-09' => [10, 100]]);
        $this->seedSales('WF-12-PET', ['2026-01-09' => [5, 50]]); // establishes the successor's "known to exist" floor
        // successor is OOS only on the last day of the RTD interval
        $this->seedOosDay('WF-12-PET', '2026-01-12');
        $this->seedInterval('gid://shopify/ProductVariant/1', 'RTD-WF-12', '2026-01-10 00:00:00', '2026-01-12 23:59:59');

        $result = $this->service(['rtd-wf-12' => 'WF-12-PET'])->estimate();

        $this->assertSame(1, $result['estimates_written']);
        $this->assertSame(0, $result['fully_substituted']);
        $this->assertDatabaseHas('lost_sales_estimates', [
            'started_at' => '2026-01-12 00:00:00',
            'ended_at' => '2026-01-12 23:59:59',
            'days_out_of_stock' => 1.00,
        ]);

        $note = DB::table('lost_sales_estimates')->value('substitution_note');
        $this->assertStringContainsString('WF-12-PET', $note);
        $this->assertStringContainsString('2 of the original 3', $note);
    }

    public function test_a_day_before_the_successors_own_earliest_known_day_is_never_treated_as_available(): void
    {
        $this->seedSales('RTD-WF-12', ['2026-01-01' => [10, 100]]);
        // Successor's earliest evidence of existing is 2026-01-10 - the interval
        // (Jan 2-4) is entirely before that, so it must count fully, not be excluded.
        $this->seedSales('WF-12-PET', ['2026-01-10' => [5, 50]]);
        $this->seedInterval('gid://shopify/ProductVariant/1', 'RTD-WF-12', '2026-01-02 00:00:00', '2026-01-04 23:59:59');

        $result = $this->service(['rtd-wf-12' => 'WF-12-PET'])->estimate();

        $this->assertSame(1, $result['estimates_written']);
        $this->assertDatabaseHas('lost_sales_estimates', ['days_out_of_stock' => 3.00]);
    }

    public function test_a_split_into_two_ranges_produces_two_rows_sharing_the_same_baseline(): void
    {
        $this->seedSales('RTD-WF-12', ['2026-01-04' => [10, 100]]);
        $this->seedSales('WF-12-PET', ['2026-01-04' => [5, 50]]);
        // successor OOS on the first and last day only - in stock in the middle
        $this->seedOosDay('WF-12-PET', '2026-01-05');
        $this->seedOosDay('WF-12-PET', '2026-01-09');
        $this->seedInterval('gid://shopify/ProductVariant/1', 'RTD-WF-12', '2026-01-05 00:00:00', '2026-01-09 23:59:59');

        $result = $this->service(['rtd-wf-12' => 'WF-12-PET'])->estimate();

        $this->assertSame(2, $result['estimates_written']);
        $this->assertDatabaseHas('lost_sales_estimates', ['started_at' => '2026-01-05 00:00:00', 'ended_at' => '2026-01-05 23:59:59', 'days_out_of_stock' => 1.00, 'baseline_units_per_day' => 10.00]);
        $this->assertDatabaseHas('lost_sales_estimates', ['started_at' => '2026-01-09 00:00:00', 'ended_at' => '2026-01-09 23:59:59', 'days_out_of_stock' => 1.00, 'baseline_units_per_day' => 10.00]);
    }

    public function test_an_ongoing_split_range_only_marks_the_true_final_range_as_ongoing(): void
    {
        $this->seedSales('RTD-WF-12', [now()->subDays(6)->toDateString() => [10, 100]]);
        $this->seedSales('WF-12-PET', [now()->subDays(6)->toDateString() => [5, 50]]);
        // successor briefly out of stock in the middle, back in stock through today
        $this->seedOosDay('WF-12-PET', now()->subDays(3)->toDateString());
        $this->seedInterval('gid://shopify/ProductVariant/1', 'RTD-WF-12', now()->subDays(5)->startOfDay()->toDateTimeString(), null);

        $result = $this->service(['rtd-wf-12' => 'WF-12-PET'])->estimate();

        // days -5..-4 (successor in stock, excluded), -3 (successor OOS, counted, closed range),
        // -2..0/today (successor in stock again, excluded) -> exactly one 1-day range, not ongoing.
        $this->assertSame(1, $result['estimates_written']);
        $row = DB::table('lost_sales_estimates')->first();
        $this->assertNotNull($row->ended_at);
    }

    public function test_no_successor_configured_behaves_exactly_as_before(): void
    {
        $this->seedSales('BIOSTEEL HOODIE', ['2026-01-09' => [10, 100]]);
        $this->seedInterval('gid://shopify/ProductVariant/1', 'BIOSTEEL HOODIE', '2026-01-10 00:00:00', '2026-01-10 23:59:59');

        $result = $this->service([])->estimate();

        $this->assertSame(1, $result['estimates_written']);
        $this->assertNull(DB::table('lost_sales_estimates')->value('substitution_note'));
    }
}
