<?php

namespace App\Services\AspTracker;

/**
 * GraphQL selections shared by the bulk backfill and the single-order
 * re-fetch, so both paths hand OrderLineMapper the same field shapes. All
 * fields confirmed live against the Canadian Protein store (API 2026-07,
 * 2026-10-19), including two things the brief assumed differently:
 *
 * - LineItem.discountedTotalSet leaves out order-level (ACROSS) allocations
 *   and product-specific discount codes — only automatic line discounts
 *   are in it. Net is therefore computed from discountAllocations instead
 *   (OrderLineMapper), never read from discountedTotalSet.
 * - Bulk operations reject a connection inside a list field (confirmed
 *   live: "Queries that contain a connection field within a list field are
 *   not currently supported"), i.e. refunds { refundLineItems }. The bulk
 *   query leaves refunds out and reads LineItem.currentQuantity instead;
 *   OrderLineMapper estimates from that and the backfill queues those
 *   orders for an exact single-order re-fetch.
 * - Recharge subscriptions are Shopify selling plans, and the plan's
 *   discount is baked into originalUnitPriceSet (a $139.99 variant shows
 *   $97.99), not a discount allocation — hence variant.price, used by
 *   ListPriceResolver as a last-resort list price.
 */
class OrderQueries
{
    public const ORDER_FIELDS = 'id name createdAt sourceName app { name } test cancelledAt displayFinancialStatus taxesIncluded';

    public const LINE_FIELDS = <<<'GQL'
        id quantity currentQuantity sku title variantTitle isGiftCard
        product { id } variant { id price }
        sellingPlan { name }
        originalUnitPriceSet { shopMoney { amount } }
        discountAllocations {
            allocatedAmountSet { shopMoney { amount } }
            discountApplication {
                __typename allocationMethod targetSelection targetType
                ... on DiscountCodeApplication { code }
                ... on AutomaticDiscountApplication { title }
                ... on ManualDiscountApplication { title }
                ... on ScriptDiscountApplication { title }
            }
        }
        GQL;

    public const REFUND_LINE_FIELDS = 'quantity lineItem { id } subtotalSet { shopMoney { amount } }';

    /** Bulk operations want edges/node and ignore pagination arguments. No refunds — see the class docblock. */
    public static function bulkOrdersQuery(string $since): string
    {
        // Embedded in a GraphQL string literal (bulk queries take no
        // variables), so only a plain date is ever let through.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            throw new \InvalidArgumentException("Backfill start must be YYYY-MM-DD, got: {$since}");
        }

        return 'query { orders(query: "created_at:>=\''.$since.'\'") { edges { node { '.self::ORDER_FIELDS
            .' lineItems { edges { node { '.self::LINE_FIELDS.' } } }'
            .' } } } }';
    }

    public static function singleOrderQuery(): string
    {
        return 'query($id: ID!) { order(id: $id) { '.self::ORDER_FIELDS
            .' lineItems(first: 250) { pageInfo { hasNextPage endCursor } nodes { '.self::LINE_FIELDS.' } }'
            .' refunds { id refundLineItems(first: 250) { nodes { '.self::REFUND_LINE_FIELDS.' } } }'
            .' } }';
    }

    public static function moreLineItemsQuery(): string
    {
        return 'query($id: ID!, $after: String) { order(id: $id) { lineItems(first: 250, after: $after) { pageInfo { hasNextPage endCursor } nodes { '
            .self::LINE_FIELDS.' } } } }';
    }
}
