<?php

namespace App\Amazon\Backfill;

use App\Amazon\Probe\DataKioskQuery;
use Carbon\CarbonImmutable;

/**
 * The one-off grab of Data Kiosk history from before the old app's weekly
 * data starts (2024-12-29). Decided 2026-11-26, see docs/context.md.
 *
 * Data Kiosk refuses a start date more than two years back (verified live
 * 2026-11-26), so the window shrinks by a day every day:
 *  - daily grain from the Sunday before Black Friday 2024, or the earliest
 *    day Amazon still allows, to 2025-01-04
 *  - weekly grain for whole Sunday-to-Saturday weeks in the same range
 *
 * It ends on 2025-01-04 so the week of 2024-12-29 overlaps the old app's
 * data, which lets Phase 1 check the two against each other.
 *
 * Every query is one the old app or the probe has run successfully:
 * sales and costs (old NRP), traffic (old VC traffic), geography (old Geo
 * Sales, the province and city grains), and the probe's verified groups.
 */
final class HistoryGrabPlan
{
    public const TARGET_START = '2024-11-24';

    public const TARGET_END = '2025-01-04';

    /** @return array<string, DataKioskQuery> key => query, in run order */
    public static function build(CarbonImmutable $now): array
    {
        [$dailyStart, $weeklyStart] = self::starts($now);
        $end = self::TARGET_END;

        $units = ['units', 'value' => ['amount', 'currencyCode']];
        $amount = ['amount', 'currencyCode'];

        $plan = [];

        if ($dailyStart <= $end) {
            // Daily first: its earliest days are the next to age out.
            $plan['daily.sales'] = new DataKioskQuery('manufacturingView', 'DAY', $dailyStart, $end, ['asin'], [
                'shippedOrders' => ['shippedUnitsWithRevenue' => $units, 'averageSellingPrice' => $amount],
                'orders' => ['orderedUnitsWithRevenue' => $units],
            ]);
        }

        if ($weeklyStart > $end) {
            return $plan;
        }

        $weekly = fn (array $metrics, array $groupBy = ['asin']) => new DataKioskQuery('manufacturingView', 'WEEK', $weeklyStart, $end, $groupBy, $metrics);

        return $plan + [
            'weekly.sales_costs' => $weekly([
                'shippedOrders' => ['shippedUnitsWithRevenue' => $units, 'averageSellingPrice' => $amount],
                'costs' => [
                    'shippedCogs' => $amount, 'contraCogs' => $amount, 'contraCogsPerUnit' => $amount, 'netPPM',
                    'salesDiscount' => $amount, 'averageSalesDiscount' => $amount,
                ],
            ]),
            'weekly.orders' => $weekly(['orders' => [
                'unfilledOrderedUnits', 'netOrderedGMS' => $amount, 'netShipmentGMS' => $amount, 'orderedUnitsWithRevenue' => $units,
            ]]),
            'weekly.traffic_availability' => $weekly([
                'traffic' => ['glanceViews', 'lostFeaturedOffer'],
                'productAvailability' => [
                    'sellThroughRate', 'sourceableROOS', 'sellableInTransitInventory', 'unsellableInTransitInventory',
                    'sellableOnHandInventory' => $units, 'unsellableOnHandInventory' => $units, 'unhealthyInventory' => $units,
                ],
            ]),
            'weekly.sourcing' => $weekly(['sourcing' => [
                'confirmedUnits', 'mostRecentSubmitted', 'openPurchaseOrderQuantity', 'overallVendorLeadTime', 'procurableProductOOS',
                'receivedFillRate', 'vendorConfirmationRate', 'netReceived' => $units,
            ]]),
            'weekly.customer_returns' => $weekly(['customerSatisfaction' => ['customerReturns']]),
            'weekly.vendor_codes' => $weekly(['shippedOrders' => ['shippedUnitsWithRevenue' => $units]], ['vendorCode', 'asin']),
            'weekly.geo_province' => $weekly(
                ['shippedOrders' => ['shippedUnitsWithRevenue' => $units]],
                ['asin', 'shipToCountryCode', 'shipToStateOrProvince'],
            ),
            'weekly.geo_city' => $weekly(
                ['shippedOrders' => ['shippedUnitsWithRevenue' => $units]],
                ['asin', 'shipToCountryCode', 'shipToStateOrProvince', 'shipToCity'],
            ),
        ];
    }

    /**
     * The first day Amazon still accepts, with a day's margin for time zones.
     */
    public static function earliestAllowed(CarbonImmutable $now): string
    {
        return $now->utc()->startOfDay()->subYears(2)->addDay()->format('Y-m-d');
    }

    /** @return array{0: string, 1: string} daily start, weekly start */
    private static function starts(CarbonImmutable $now): array
    {
        $daily = max(self::TARGET_START, self::earliestAllowed($now));

        $weekly = CarbonImmutable::parse($daily);
        if (! $weekly->isSunday()) {
            $weekly = $weekly->next(CarbonImmutable::SUNDAY);
        }

        return [$daily, $weekly->format('Y-m-d')];
    }
}
