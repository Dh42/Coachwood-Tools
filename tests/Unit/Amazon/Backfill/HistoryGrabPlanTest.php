<?php

namespace Tests\Unit\Amazon\Backfill;

use App\Amazon\Backfill\HistoryGrabPlan;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class HistoryGrabPlanTest extends TestCase
{
    public function test_on_the_planned_run_day_it_starts_at_the_earliest_allowed_day_and_the_next_sunday(): void
    {
        // Friday 2026-11-27: Amazon allows 2024-11-27 onward; a day's margin gives 2024-11-28.
        $plan = HistoryGrabPlan::build(CarbonImmutable::parse('2026-11-27 15:00:00', 'UTC'));

        $this->assertCount(9, $plan);
        $this->assertSame(['2024-11-28', '2025-01-04', 'DAY'], [$plan['daily.sales']->startDate, $plan['daily.sales']->endDate, $plan['daily.sales']->aggregateBy]);
        foreach ($plan as $key => $query) {
            if ($key !== 'daily.sales') {
                $this->assertSame(['2024-12-01', '2025-01-04', 'WEEK'], [$query->startDate, $query->endDate, $query->aggregateBy], $key);
            }
        }
        $this->assertSame('daily.sales', array_key_first($plan), 'Daily runs first: its days age out first.');
    }

    public function test_before_the_limit_bites_it_starts_on_the_sunday_before_black_friday(): void
    {
        $plan = HistoryGrabPlan::build(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));

        $this->assertSame('2024-11-24', $plan['daily.sales']->startDate);
        $this->assertSame('2024-11-24', $plan['weekly.sales_costs']->startDate);
    }

    public function test_once_no_whole_week_is_left_only_the_daily_query_remains(): void
    {
        $plan = HistoryGrabPlan::build(CarbonImmutable::parse('2026-12-31 12:00:00', 'UTC'));

        $this->assertSame(['daily.sales'], array_keys($plan));
        $this->assertSame('2025-01-01', $plan['daily.sales']->startDate);
    }

    public function test_once_the_window_has_passed_the_plan_is_empty(): void
    {
        $this->assertSame([], HistoryGrabPlan::build(CarbonImmutable::parse('2027-01-05 12:00:00', 'UTC')));
    }

    public function test_the_geography_queries_use_the_old_geo_grains(): void
    {
        $plan = HistoryGrabPlan::build(CarbonImmutable::parse('2026-11-27 15:00:00', 'UTC'));

        $this->assertSame(['asin', 'shipToCountryCode', 'shipToStateOrProvince'], $plan['weekly.geo_province']->groupBy);
        $this->assertSame(['asin', 'shipToCountryCode', 'shipToStateOrProvince', 'shipToCity'], $plan['weekly.geo_city']->groupBy);
        $this->assertSame(['vendorCode', 'asin'], $plan['weekly.vendor_codes']->groupBy);
        $this->assertContains('costs.netPPM', $plan['weekly.sales_costs']->fields());
        $this->assertContains('traffic.lostFeaturedOffer', $plan['weekly.traffic']->fields());
    }

    public function test_it_asks_for_no_weekly_inventory_which_amazon_keeps_for_only_8_weeks(): void
    {
        foreach (HistoryGrabPlan::build(CarbonImmutable::parse('2026-11-27 15:00:00', 'UTC')) as $key => $query) {
            foreach ($query->fields() as $field) {
                $this->assertStringStartsNotWith('productAvailability.', $field, $key);
            }
        }
    }
}
