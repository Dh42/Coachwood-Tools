<?php

namespace App\Services\AspTracker;

use App\Services\CombinedShipping\ShopifyGraphQlClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * Re-fetches one order by id in OrderQueries' shape — the webhook queue and
 * the catch-up never trust a webhook payload's completeness, they always
 * come back here.
 */
class OrderFetcher
{
    public static function client(string $store): ShopifyGraphQlClient
    {
        $brand = config("asp_tracker.stores.{$store}.brand");
        if ($brand === null) {
            throw new InvalidArgumentException("Unknown ASP tracker store: {$store}");
        }

        return new ShopifyGraphQlClient($brand);
    }

    /** @return array|null null when Shopify no longer has the order (deleted) */
    public function fetch(string $store, int $orderId): ?array
    {
        $client = self::client($store);
        $gid = "gid://shopify/Order/{$orderId}";

        $result = $client->query(OrderQueries::singleOrderQuery(), ['id' => $gid]);
        self::throwOnErrors($result, "order {$orderId}");

        $order = $result['data']['order'] ?? null;
        if ($order === null) {
            return null;
        }

        $lines = $order['lineItems']['nodes'];
        $page = $order['lineItems']['pageInfo'];
        while ($page['hasNextPage']) {
            $more = $client->query(OrderQueries::moreLineItemsQuery(), ['id' => $gid, 'after' => $page['endCursor']]);
            self::throwOnErrors($more, "order {$orderId} line items");
            array_push($lines, ...$more['data']['order']['lineItems']['nodes']);
            $page = $more['data']['order']['lineItems']['pageInfo'];
        }

        $order['lineItems'] = $lines;
        $order['refunds'] = array_map(fn (array $refund) => [
            'id' => $refund['id'],
            'refundLineItems' => $refund['refundLineItems']['nodes'] ?? [],
        ], $order['refunds'] ?? []);

        return $order;
    }

    public static function throwOnErrors(array $result, string $context): void
    {
        if (! empty($result['errors'])) {
            throw new RuntimeException("Shopify error fetching {$context}: ".($result['errors'][0]['message'] ?? json_encode($result['errors'])));
        }
    }
}
