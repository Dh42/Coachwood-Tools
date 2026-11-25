<?php

namespace Tests\Unit\Amazon\Probe;

use App\Amazon\Probe\DataKioskQuery;
use PHPUnit\Framework\TestCase;

class DataKioskQueryTest extends TestCase
{
    private function query(): DataKioskQuery
    {
        return new DataKioskQuery('manufacturingView', 'WEEK', '2026-10-25', '2026-11-21', ['asin'], [
            'productAvailability' => ['sourceableROOS', 'sellableOnHandInventory' => ['units', 'value' => ['amount', 'currencyCode']]],
            'sourcing' => ['procurableProductOOS'],
        ]);
    }

    public function test_it_renders_the_graphql_query(): void
    {
        $this->assertSame(
            'query { analytics_vendorAnalytics_2024_09_30 { manufacturingView(aggregateBy: WEEK, startDate: "2026-10-25", endDate: "2026-11-21", currencyCode: "CAD") { startDate endDate metrics { groupByKey { asin } metrics { productAvailability { sourceableROOS sellableOnHandInventory { units value { amount currencyCode } } } sourcing { procurableProductOOS } } } } } }',
            $this->query()->toGraphQl(),
        );
    }

    public function test_it_lists_requested_leaf_fields(): void
    {
        $this->assertSame([
            'productAvailability.sourceableROOS',
            'productAvailability.sellableOnHandInventory.units',
            'productAvailability.sellableOnHandInventory.value.amount',
            'productAvailability.sellableOnHandInventory.value.currencyCode',
            'sourcing.procurableProductOOS',
        ], $this->query()->fields());
    }

    public function test_without_removes_fields_and_empty_groups(): void
    {
        $pruned = $this->query()->without(['procurableProductOOS', 'sellableOnHandInventory']);

        $this->assertSame(['productAvailability.sourceableROOS'], $pruned->fields());
        $this->assertStringNotContainsString('sourcing', $pruned->toGraphQl());
        $this->assertTrue($pruned->without(['sourceableROOS'])->isEmpty());
    }

    public function test_it_reads_field_names_from_amazons_validation_error(): void
    {
        // The exact message the old app's vc-traffic:snapshot got from Amazon (context.md, 2026-08-27).
        $error = "Validation error of type FieldUndefined: Field 'procurableProductOOS' in type 'ProductAvailability' is undefined @ 'x'";

        $this->assertSame(['procurableProductOOS'], DataKioskQuery::undefinedFields($error));
        $this->assertSame([], DataKioskQuery::undefinedFields('Access denied'));
    }
}
