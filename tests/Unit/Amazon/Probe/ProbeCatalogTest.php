<?php

namespace Tests\Unit\Amazon\Probe;

use App\Amazon\Probe\Capability;
use App\Amazon\Probe\ProbeCatalog;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ProbeCatalogTest extends TestCase
{
    public function test_keys_are_unique_and_every_family_is_covered(): void
    {
        $caps = ProbeCatalog::build(CarbonImmutable::parse('2026-11-25 12:00:00'), 'B0TESTASIN');
        $keys = array_map(fn (Capability $c) => $c->key, $caps);

        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertCount(25, $caps);
        foreach ([Capability::REPORT, Capability::DATA_KIOSK, Capability::API] as $family) {
            $this->assertNotEmpty(array_filter($caps, fn (Capability $c) => $c->family === $family));
        }
    }

    public function test_weekly_windows_are_whole_sunday_to_saturday_weeks_at_least_three_days_old(): void
    {
        // Wednesday 2026-11-25: three days back is Sunday 11-22, so the last full week ends Saturday 11-21.
        $caps = collect(ProbeCatalog::build(CarbonImmutable::parse('2026-11-25 12:00:00')))->keyBy('key');

        $inventory = $caps['report.inventory_manufacturing']->request['options'];
        $this->assertSame('2026-10-25T00:00:00Z', $inventory['dataStartTime']);
        $this->assertSame('2026-11-21T23:59:59Z', $inventory['dataEndTime']);

        $searchTerms = $caps['report.brand_search_terms']->request['options'];
        $this->assertSame('2026-11-15T00:00:00Z', $searchTerms['dataStartTime']);

        $query = $caps['data_kiosk.product_availability']->request['query'];
        $this->assertSame(['2026-10-25', '2026-11-21'], [$query->startDate, $query->endDate]);
    }

    public function test_the_offer_check_is_skipped_without_an_asin(): void
    {
        $caps = collect(ProbeCatalog::build(CarbonImmutable::parse('2026-11-25 12:00:00')))->keyBy('key');

        $this->assertSame('No --asin given.', $caps['api.seller_offers_ca']->request['skip_reason']);
        $this->assertSame('A2EUQ1WTGCTBG2', $caps['api.seller_offers_ca']->marketplaceId);
    }
}
