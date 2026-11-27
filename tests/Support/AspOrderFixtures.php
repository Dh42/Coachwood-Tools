<?php

namespace Tests\Support;

/**
 * Orders in OrderQueries' shape (as OrderFetcher returns them), modelled on
 * real Canadian Protein orders sampled 2026-10-19.
 */
trait AspOrderFixtures
{
    protected function configureAspStore(): void
    {
        config([
            'combined_shipping.brands.CP.store' => 'cp-test.myshopify.com',
            'combined_shipping.brands.CP.access_token' => 'test-token',
            'asp_tracker.stores.CP.webhook_secret' => 'asp-secret',
        ]);
    }

    protected static function money(float $amount): array
    {
        return ['shopMoney' => ['amount' => number_format($amount, 2, '.', '')]];
    }

    protected static function allocation(float $amount, string $method, string $typename, string $label, string $targetType = 'LINE_ITEM'): array
    {
        $application = ['__typename' => $typename, 'allocationMethod' => $method, 'targetSelection' => $method === 'ACROSS' ? 'ALL' : 'ENTITLED', 'targetType' => $targetType];
        $application[$typename === 'DiscountCodeApplication' ? 'code' : 'title'] = $label;

        return ['allocatedAmountSet' => self::money($amount), 'discountApplication' => $application];
    }

    protected static function lineItem(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => "gid://shopify/LineItem/{$id}",
            'quantity' => 1,
            'sku' => 'WPI-1KG-CHOC',
            'title' => 'Whey Protein Isolate - 1 kg (2.2 lb)',
            'variantTitle' => 'Chocolate',
            'isGiftCard' => false,
            'product' => ['id' => 'gid://shopify/Product/500'],
            'variant' => ['id' => 'gid://shopify/ProductVariant/900', 'price' => '79.99'],
            'sellingPlan' => null,
            'originalUnitPriceSet' => self::money(79.99),
            'discountAllocations' => [],
        ], $overrides);
    }

    protected static function order(int $id, array $lineItems, array $overrides = []): array
    {
        return array_replace([
            'id' => "gid://shopify/Order/{$id}",
            'name' => "#{$id}",
            'createdAt' => '2026-10-15T16:00:00Z',
            'sourceName' => 'web',
            'app' => ['name' => 'Online Store'],
            'test' => false,
            'cancelledAt' => null,
            'displayFinancialStatus' => 'PAID',
            'taxesIncluded' => false,
            'lineItems' => $lineItems,
            'refunds' => [],
        ], $overrides);
    }

    protected static function refund(string $id, array $lines): array
    {
        return [
            'id' => "gid://shopify/Refund/{$id}",
            'refundLineItems' => array_map(fn (array $l) => [
                'quantity' => $l[1],
                'lineItem' => ['id' => "gid://shopify/LineItem/{$l[0]}"],
                'subtotalSet' => self::money($l[2]),
            ], $lines),
        ];
    }

    /** Wraps an order the way the single-order GraphQL query returns it. */
    protected static function graphQlOrderResponse(?array $order): array
    {
        if ($order === null) {
            return ['data' => ['order' => null]];
        }

        $order['lineItems'] = ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => $order['lineItems']];
        $order['refunds'] = array_map(fn (array $r) => ['id' => $r['id'], 'refundLineItems' => ['nodes' => $r['refundLineItems']]], $order['refunds']);

        return ['data' => ['order' => $order]];
    }
}
