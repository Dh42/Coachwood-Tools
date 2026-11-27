<?php

namespace App\Services\AspTracker;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Turns one Shopify order (OrderQueries' field shape, with lineItems and
 * refunds[].refundLineItems already flattened to plain lists) into
 * asp_sales_lines + asp_line_discounts rows.
 *
 * Net line total = original unit price × quantity − every non-shipping
 * discount allocation on the line. Shopify has already prorated order-level
 * discounts across lines (to the penny); those allocations are taken as-is,
 * never re-allocated. Allocation method decides the bucket: ACROSS → order
 * level (Rivo, "$X off your order" codes, manual order discounts), EACH /
 * ONE → line level (automatic sale pricing, product-specific codes).
 *
 * Refunds: RefundLineItem.subtotalSet is already net of both line- and
 * order-level discounts (confirmed live: a $145.50 refund on a line with $12
 * of Rivo allocated). Order edits that remove a line show up the same way,
 * as a $0 refund with restockType CANCEL, so refunded_qty covers both.
 * Refunds with no line items (shipping refunds, goodwill credits) aren't
 * attributed to any line — they're only counted.
 *
 * Bulk-export orders carry no refunds at all (see OrderQueries). For those,
 * refunded units = quantity − currentQuantity and the refunded amount is
 * that share of the line's net — the same proration Shopify's own refund
 * subtotals showed in every sampled order — and refunds_estimated is set
 * so the caller queues an exact re-fetch.
 */
class OrderLineMapper
{
    public function __construct(private readonly string $store) {}

    /**
     * @return array{skip: bool, order_id: int, lines: list<array>, discounts: list<array>, unattributed_refunds: int, refunds_estimated: bool}
     */
    public function map(array $order): array
    {
        $orderId = self::gidToId($order['id']);
        $result = ['skip' => false, 'order_id' => $orderId, 'lines' => [], 'discounts' => [], 'unattributed_refunds' => 0, 'refunds_estimated' => false];
        $refundsKnown = array_key_exists('refunds', $order);

        if (! empty($order['test'])) {
            $result['skip'] = true;

            return $result;
        }

        if (! empty($order['taxesIncluded'])) {
            // Every amount here assumes pre-tax prices — tax-inclusive orders
            // would silently inflate ASP by the tax rate. CP doesn't use them
            // (shop.taxesIncluded false, confirmed live); fail loudly if that
            // ever changes rather than ingest wrong numbers.
            throw new RuntimeException("Order {$order['name']} has taxesIncluded = true — the ASP tracker assumes tax-exclusive prices. Stopping instead of ingesting wrong numbers.");
        }

        $timezone = config("asp_tracker.stores.{$this->store}.timezone", 'UTC');
        $createdAt = CarbonImmutable::parse($order['createdAt'])->utc();
        $local = $createdAt->setTimezone($timezone);

        [$refunds, $result['unattributed_refunds']] = $this->refundsByLine($order['refunds'] ?? []);

        foreach ($order['lineItems'] ?? [] as $line) {
            // Selling a gift card isn't product revenue (Shopify's own net
            // sales leaves it out too); redeeming one is a payment method,
            // not a discount, so nothing else needs adjusting for gift cards.
            if (! empty($line['isGiftCard'])) {
                continue;
            }

            $lineId = self::gidToId($line['id']);
            $quantity = (int) $line['quantity'];
            $originalUnit = self::money($line['originalUnitPriceSet'] ?? null);

            $lineLevel = 0.0;
            $orderLevel = 0.0;
            $labels = [];

            foreach ($line['discountAllocations'] ?? [] as $allocation) {
                $application = $allocation['discountApplication'] ?? [];
                if (($application['targetType'] ?? null) === 'SHIPPING_LINE') {
                    continue;
                }

                $amount = self::money($allocation['allocatedAmountSet'] ?? null);
                $level = ($application['allocationMethod'] ?? null) === 'ACROSS' ? 'order' : 'line';
                $level === 'order' ? $orderLevel += $amount : $lineLevel += $amount;

                $label = (string) ($application['code'] ?? $application['title'] ?? 'Unlabelled discount');
                $labels[$label] = true;

                $result['discounts'][] = [
                    'line_item_id' => $lineId,
                    'order_id' => $orderId,
                    'label' => mb_substr($label, 0, 255),
                    'label_group' => mb_substr(self::labelGroup($label), 0, 255),
                    'discount_type' => self::discountType($application['__typename'] ?? ''),
                    'level' => $level,
                    'amount' => round($amount, 2),
                ];
            }

            $net = round($originalUnit * $quantity - $lineLevel - $orderLevel, 2);
            $isSubscription = ! empty($line['sellingPlan']);

            if ($refundsKnown) {
                $refundedQty = $refunds[$lineId]['qty'] ?? 0;
                $refundedAmount = round($refunds[$lineId]['amount'] ?? 0, 2);
            } else {
                $refundedQty = max(0, $quantity - (int) ($line['currentQuantity'] ?? $quantity));
                $refundedAmount = $quantity > 0 ? round($net * $refundedQty / $quantity, 2) : 0.0;
                $result['refunds_estimated'] = $result['refunds_estimated'] || $refundedQty > 0;
            }

            $row = [
                'line_item_id' => $lineId,
                'store' => $this->store,
                'order_id' => $orderId,
                'order_name' => (string) $order['name'],
                'order_created_at' => $createdAt->format('Y-m-d H:i:s'),
                'order_date' => $local->toDateString(),
                'week_start' => $local->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'source_name' => $order['sourceName'] ?? null,
                'app_name' => $order['app']['name'] ?? null,
                'product_id' => isset($line['product']['id']) ? self::gidToId($line['product']['id']) : null,
                'variant_id' => isset($line['variant']['id']) ? self::gidToId($line['variant']['id']) : null,
                'sku' => filled($line['sku'] ?? null) ? mb_substr($line['sku'], 0, 120) : null,
                'product_title' => isset($line['title']) ? mb_substr($line['title'], 0, 255) : null,
                'variant_title' => isset($line['variantTitle']) ? mb_substr($line['variantTitle'], 0, 255) : null,
                'quantity' => $quantity,
                'original_unit_price' => round($originalUnit, 2),
                'variant_price' => isset($line['variant']['price']) ? round((float) $line['variant']['price'], 2) : null,
                'line_level_discount' => round($lineLevel, 2),
                'order_level_discount' => round($orderLevel, 2),
                'net_line_total' => $net,
                'discount_labels' => $labels === [] ? null : mb_substr(implode(', ', array_keys($labels)), 0, 500),
                'selling_plan_name' => $isSubscription ? mb_substr((string) ($line['sellingPlan']['name'] ?? ''), 0, 120) : null,
                'is_subscription' => $isSubscription,
                'is_free_item' => $net <= 0,
                'refunded_qty' => $refundedQty,
                'refunded_amount' => $refundedAmount,
                'financial_status' => $order['displayFinancialStatus'] ?? null,
                'cancelled_at' => isset($order['cancelledAt']) ? CarbonImmutable::parse($order['cancelledAt'])->utc()->format('Y-m-d H:i:s') : null,
            ];

            $row['content_hash'] = self::hash($row);

            // One-time lines are their own list price; subscription lines get
            // theirs from ListPriceResolver. Set only on insert — see
            // SalesLineWriter::UPDATE_COLUMNS.
            $row['list_unit_price'] = $isSubscription ? null : $row['original_unit_price'];
            $row['list_price_source'] = $isSubscription ? null : 'own';

            $result['lines'][] = $row;
        }

        return $result;
    }

    /** @return array{0: array<int, array{qty: int, amount: float}>, 1: int} */
    private function refundsByLine(array $refunds): array
    {
        $byLine = [];
        $unattributed = 0;

        foreach ($refunds as $refund) {
            $refundLines = $refund['refundLineItems'] ?? [];
            if ($refundLines === []) {
                $unattributed++;

                continue;
            }

            foreach ($refundLines as $refundLine) {
                $lineId = self::gidToId($refundLine['lineItem']['id']);
                $byLine[$lineId]['qty'] = ($byLine[$lineId]['qty'] ?? 0) + (int) $refundLine['quantity'];
                $byLine[$lineId]['amount'] = ($byLine[$lineId]['amount'] ?? 0) + self::money($refundLine['subtotalSet'] ?? null);
            }
        }

        return [$byLine, $unattributed];
    }

    public static function labelGroup(string $label): string
    {
        foreach (config('asp_tracker.label_groups', []) as $pattern => $group) {
            if (preg_match($pattern, $label)) {
                return $group;
            }
        }

        return $label;
    }

    private static function discountType(string $typename): string
    {
        return match ($typename) {
            'DiscountCodeApplication' => 'DISCOUNT_CODE',
            'AutomaticDiscountApplication' => 'AUTOMATIC',
            'ManualDiscountApplication' => 'MANUAL',
            'ScriptDiscountApplication' => 'SCRIPT',
            default => 'OTHER',
        };
    }

    private static function money(?array $set): float
    {
        return (float) ($set['shopMoney']['amount'] ?? 0);
    }

    public static function gidToId(string $gid): int
    {
        return (int) substr($gid, strrpos($gid, '/') + 1);
    }

    /**
     * Over everything Shopify supplies about the sale — lets a re-ingest skip
     * unchanged lines and report a true change count. variant_price is left
     * out: it's the variant's price *today* (a fallback list price only), so
     * it moving shouldn't make an old line count as changed.
     */
    public static function hash(array $row): string
    {
        unset($row['variant_price'], $row['content_hash'], $row['list_unit_price'], $row['list_price_source']);
        $row['is_subscription'] = (int) $row['is_subscription'];
        $row['is_free_item'] = (int) $row['is_free_item'];

        return sha1(json_encode($row));
    }
}
