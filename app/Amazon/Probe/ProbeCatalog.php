<?php

namespace App\Amazon\Probe;

use Carbon\CarbonImmutable;

/**
 * What `amazon:probe` tries: spec section 8 "To probe", plus both vendor
 * codes (decision D9) and Amazon.ca offer lookups with the seller token
 * (decision D8). Each capability is requested once.
 *
 * Report options and API parameters follow Amazon's published schemas and
 * models (amzn/selling-partner-api-models, read 2026-11-25). From docs, not
 * yet verified on this account; that is what the probe is for.
 */
final class ProbeCatalog
{
    public const AMAZON_CA = 'A2EUQ1WTGCTBG2';

    /** Vendor reports lag about three days (assumed, matching the old app's pulls). */
    private const REPORT_LAG_DAYS = 3;

    /** @return list<Capability> */
    public static function build(CarbonImmutable $now, ?string $asin = null): array
    {
        $now = $now->utc();
        $vendorMarketplace = config('services.sp_api.vendor_marketplace_id') ?: self::AMAZON_CA;

        // Last four complete Sunday-to-Saturday weeks, ending at least REPORT_LAG_DAYS ago.
        $weekEnd = $now->subDays(self::REPORT_LAG_DAYS)->startOfDay();
        while ($weekEnd->dayOfWeek !== CarbonImmutable::SATURDAY) {
            $weekEnd = $weekEnd->subDay();
        }
        $fourWeeksStart = $weekEnd->subDays(27);
        $lastWeekStart = $weekEnd->subDays(6);
        $dailyEnd = $now->subDays(self::REPORT_LAG_DAYS)->startOfDay();
        $dailyStart = $dailyEnd->subDays(13);
        $realTimeEnd = $now->startOfHour()->subHour();
        $realTimeStart = $realTimeEnd->subHours(24);

        $iso = fn (CarbonImmutable $d) => $d->format('Y-m-d\TH:i:s\Z');
        $day = fn (CarbonImmutable $d) => $d->format('Y-m-d');
        $range = fn (CarbonImmutable $start, CarbonImmutable $end) => [
            'dataStartTime' => $iso($start->startOfDay()),
            'dataEndTime' => $iso($end->endOfDay()->startOfSecond()),
        ];

        $units = ['units', 'value' => ['amount', 'currencyCode']];
        $amount = ['amount', 'currencyCode'];

        $report = fn (string $key, string $label, string $wantedFor, string $type, array $body, bool $download = true, ?array $expect = null) => new Capability(
            "report.{$key}", $label, $wantedFor, Capability::REPORT, 'vendor', $vendorMarketplace,
            array_filter(['reportType' => $type, 'options' => $body, 'download' => $download, 'expect' => $expect], fn ($v) => $v !== null),
        );

        // The spec section 8 fields, as named in Amazon's vendorInventoryReport schema.
        $inventoryFields = [
            'openPurchaseOrderUnits', 'aged90PlusDaysSellableInventoryUnits', 'unhealthyInventoryUnits', 'vendorConfirmationRate',
            'netReceivedInventoryUnits', 'averageVendorLeadTimeDays', 'unfilledCustomerOrderedUnits', 'sellThroughRate', 'sellableOnHandInventoryUnits',
        ];

        $kiosk = fn (string $key, string $label, string $wantedFor, DataKioskQuery $query) => new Capability(
            "data_kiosk.{$key}", $label, $wantedFor, Capability::DATA_KIOSK, 'vendor', $vendorMarketplace, ['query' => $query],
        );

        $weekly = fn (array $metrics, array $groupBy = ['asin'], string $view = 'manufacturingView') => new DataKioskQuery(
            $view, 'WEEK', $day($fourWeeksStart), $day($weekEnd), $groupBy, $metrics,
        );

        return [
            // Reports API, vendor token.
            $report('inventory_manufacturing', 'Inventory report, manufacturing view, weekly', 'Open PO units, aged 90-day, unhealthy units, confirmation rate, fill rate, lead time, unfilled units', 'GET_VENDOR_INVENTORY_REPORT',
                ['reportOptions' => ['reportPeriod' => 'WEEK', 'distributorView' => 'MANUFACTURING', 'sellingProgram' => 'RETAIL']] + $range($fourWeeksStart, $weekEnd), expect: $inventoryFields),
            $report('inventory_sourcing', 'Inventory report, sourcing view, weekly', 'Same fields; some may only fill in the sourcing view', 'GET_VENDOR_INVENTORY_REPORT',
                ['reportOptions' => ['reportPeriod' => 'WEEK', 'distributorView' => 'SOURCING', 'sellingProgram' => 'RETAIL']] + $range($fourWeeksStart, $weekEnd), expect: $inventoryFields),
            $report('traffic', 'Traffic report, weekly', 'Fallback if Data Kiosk traffic fields fail', 'GET_VENDOR_TRAFFIC_REPORT',
                ['reportOptions' => ['reportPeriod' => 'WEEK']] + $range($fourWeeksStart, $weekEnd)),
            $report('net_ppm', 'Net pure product margin report, weekly', 'Fallback if Data Kiosk cost fields fail', 'GET_VENDOR_NET_PURE_PRODUCT_MARGIN_REPORT',
                ['reportOptions' => ['reportPeriod' => 'WEEK']] + $range($fourWeeksStart, $weekEnd)),
            $report('real_time_sales', 'Real-time sales report, last 24 hours', 'Deferred hourly signals', 'GET_VENDOR_REAL_TIME_SALES_REPORT',
                ['reportOptions' => ['currencyCode' => 'CAD'], 'dataStartTime' => $iso($realTimeStart), 'dataEndTime' => $iso($realTimeEnd)]),
            $report('real_time_inventory', 'Real-time inventory report, last 24 hours', 'Deferred hourly signals', 'GET_VENDOR_REAL_TIME_INVENTORY_REPORT',
                ['dataStartTime' => $iso($realTimeStart), 'dataEndTime' => $iso($realTimeEnd)]),
            $report('real_time_traffic', 'Real-time traffic report, last 24 hours', 'Deferred hourly signals', 'GET_VENDOR_REAL_TIME_TRAFFIC_REPORT',
                ['dataStartTime' => $iso($realTimeStart), 'dataEndTime' => $iso($realTimeEnd)]),
            $report('promotions', 'Promotion performance report, last 12 months', 'Promotion actuals, demand events', 'GET_PROMOTION_PERFORMANCE_REPORT',
                ['reportOptions' => ['promotionStartDateFrom' => $iso($now->subYear()->startOfDay()), 'promotionStartDateTo' => $iso($now->startOfDay())]]),
            $report('coupons', 'Coupon performance report, last 12 months', 'Promotion actuals, demand events', 'GET_COUPON_PERFORMANCE_REPORT',
                ['reportOptions' => ['campaignStartDateFrom' => $iso($now->subYear()->startOfDay()), 'campaignStartDateTo' => $iso($now->startOfDay())]]),
            // Search terms cover the whole marketplace and can be very large: confirm it builds, do not download.
            $report('brand_search_terms', 'Brand Analytics search terms, last week', 'Deferred competing-brand visibility', 'GET_BRAND_ANALYTICS_SEARCH_TERMS_REPORT',
                ['reportOptions' => ['reportPeriod' => 'WEEK']] + $range($lastWeekStart, $weekEnd), download: false),
            $report('brand_market_basket', 'Brand Analytics market basket, last week', 'Deferred competing-brand visibility', 'GET_BRAND_ANALYTICS_MARKET_BASKET_REPORT',
                ['reportOptions' => ['reportPeriod' => 'WEEK']] + $range($lastWeekStart, $weekEnd)),

            // Data Kiosk, vendor token. One query per metric group.
            $kiosk('product_availability', 'Product availability fields, weekly', 'sourceableROOS scale, in-transit and unhealthy inventory', $weekly(['productAvailability' => [
                'sellThroughRate', 'sourceableROOS', 'ultraFastTrack', 'sellableInTransitInventory', 'unsellableInTransitInventory',
                'sellableOnHandInventory' => $units, 'unsellableOnHandInventory' => $units, 'unhealthyInventory' => $units,
            ]])),
            $kiosk('sourcing_manufacturing', 'Sourcing fields, manufacturing view, weekly', 'Open PO quantity, lead time, fill and confirmation rates', $weekly(['sourcing' => [
                'confirmedUnits', 'mostRecentSubmitted', 'openPurchaseOrderQuantity', 'overallVendorLeadTime', 'procurableProductOOS',
                'receivedFillRate', 'vendorConfirmationRate', 'netReceived' => $units,
            ]])),
            $kiosk('sourcing_sourcing_view', 'Sourcing fields, sourcing view, weekly', 'Same fields, in case they only fill in the sourcing view', $weekly(['sourcing' => [
                'confirmedUnits', 'mostRecentSubmitted', 'openPurchaseOrderQuantity', 'overallVendorLeadTime', 'procurableProductOOS',
                'receivedFillRate', 'vendorConfirmationRate', 'netReceived' => $units,
            ]], view: 'sourcingView')),
            $kiosk('forecasting', 'Forecast fields, weekly', 'Cross-check of forecast vintages', $weekly(['forecasting' => [
                'weekNumber', 'startDate', 'endDate', 'demand' => ['mean'],
            ]])),
            $kiosk('orders', 'Order fields, weekly', 'Ordered units and revenue, unfilled units', $weekly(['orders' => [
                'unfilledOrderedUnits', 'netOrderedGMS' => $amount, 'netShipmentGMS' => $amount, 'orderedUnitsWithRevenue' => $units,
            ]])),
            $kiosk('vendor_codes', 'Rows per vendor code, weekly', 'Both vendor codes (Z3091, GQ8J9) under one authorization', $weekly(
                ['shippedOrders' => ['shippedUnitsWithRevenue' => ['units']]], ['vendorCode', 'asin'],
            )),
            $kiosk('daily', 'Daily grain, last 14 days', 'Daily facts with ASP, finer price-change timing', new DataKioskQuery(
                'manufacturingView', 'DAY', $day($dailyStart), $day($dailyEnd), ['asin'],
                ['shippedOrders' => ['shippedUnitsWithRevenue' => $units, 'averageSellingPrice' => $amount], 'orders' => ['orderedUnitsWithRevenue' => ['units']]],
            )),
            $kiosk('history_2023', 'Weekly history for 2023', 'Seasonality needs more than 23 months', new DataKioskQuery(
                'manufacturingView', 'WEEK', '2023-01-01', '2023-12-30', ['asin'], ['shippedOrders' => ['shippedUnitsWithRevenue' => ['units']]],
            )),
            $kiosk('customer_returns', 'Customer returns, weekly', 'Returns signal for exceptions', $weekly(['customerSatisfaction' => ['customerReturns']])),

            // Synchronous APIs.
            new Capability('api.finance_remittance_headers', 'Finance Remittance getRemittanceHeaders, last 90 days', 'Deductions Tracker', Capability::API, 'vendor', $vendorMarketplace, [
                'path' => '/finances/remittances/2026-03-17/remittances',
                'query' => ['marketplaceId' => $vendorMarketplace, 'startDate' => $iso($now->subDays(90)->startOfDay()), 'endDate' => $iso($now->startOfMinute())],
                'capture' => 'uniquePaymentId',
            ]),
            new Capability('api.finance_remittance_detail', 'Finance Remittance getRemittance, one remittance', 'Deductions Tracker line items', Capability::API, 'vendor', $vendorMarketplace, [
                'path' => '/finances/remittances/2026-03-17/remittances/{uniquePaymentId}',
                'query' => ['marketplaceId' => $vendorMarketplace],
                'needs' => ['from' => 'api.finance_remittance_headers', 'value' => 'uniquePaymentId'],
            ]),
            new Capability('api.vendor_orders_by_code', 'Purchase orders per vendor code, last 60 days', 'Both vendor codes on Vendor Orders', Capability::API, 'vendor', $vendorMarketplace, [
                'path' => '/vendor/orders/v1/purchaseOrders',
                'query' => ['createdAfter' => $iso($now->subDays(60)->startOfDay()), 'limit' => 100],
            ]),
            new Capability('api.seller_marketplaces', 'Seller marketplace participations', 'Whether the seller token covers Amazon.ca (D8)', Capability::API, 'seller', 'all', [
                'path' => '/sellers/v1/marketplaceParticipations',
                'query' => [],
            ]),
            new Capability('api.seller_offers_ca', 'Offers for one ASIN on Amazon.ca, seller token', 'Other sellers on BioSteel listings (D8)', Capability::API, 'seller', self::AMAZON_CA, [
                'path' => $asin === null ? null : '/products/pricing/v0/items/'.rawurlencode($asin).'/offers',
                'query' => ['MarketplaceId' => self::AMAZON_CA, 'ItemCondition' => 'New'],
                'skip_reason' => $asin === null ? 'No --asin given.' : null,
            ]),
        ];
    }
}
