<?php

namespace App\Services\Stockout;

use RuntimeException;

/**
 * Resolves which variants are actually eligible to register a real
 * stockout, per the brief's §6 rule: inventory tracking enabled,
 * `inventoryPolicy: DENY` (a `CONTINUE` variant never blocks a sale — no
 * stockout, no loss), product active, and published to the Online Store
 * channel.
 *
 * `onlineStoreUrl` (null when not published there) is used for the last
 * check, not `publishedOnCurrentPublication` — confirmed live 2026-09-27
 * that field errors with "Your app doesn't have a publication for this
 * shop," since it's scoped to the *calling app's own* storefront
 * publication, which a backend-only app like this one doesn't have.
 * `onlineStoreUrl` needs no such context and caught a real case live:
 * "BIOSTEEL HOODIE" is `status: ACTIVE` but `onlineStoreUrl: null` — active
 * in Shopify's system but not actually published to the Online Store,
 * despite showing up in a naive "what's out of stock" query.
 *
 * Keyed by product_title because that's the only granularity
 * `inventory_history_daily` (ShopifyQL) has to join against — a product
 * with more than one eligible variant means the historical daily figure
 * can't be attributed to a single SKU, which the caller surfaces as a
 * lower-confidence, multi-variant interval.
 *
 * Also excludes merch by `productType` (config('stockout.excluded_product_types'))
 * — confirmed with the user 2026-09-28 that BioSteel's core business is
 * supplements/drinks, merch is incidental, and only real core products
 * should count toward lost-sales measurement. A real, accepted gap: 106 of
 * 471 products have a BLANK productType (a genuine mix of core SKUs and
 * merch with no type set), which this filter can't sort — the user's fix is
 * tagging those in Shopify Admin over time, not an app-side heuristic.
 */
class CatalogEligibilityResolver
{
    private const QUERY = <<<'GQL'
        query($cursor: String) {
            products(first: 100, after: $cursor, query: "status:active") {
                pageInfo { hasNextPage endCursor }
                nodes {
                    title
                    status
                    productType
                    onlineStoreUrl
                    variants(first: 50) {
                        nodes {
                            id
                            sku
                            inventoryPolicy
                            inventoryItem { tracked }
                        }
                    }
                }
            }
        }
        GQL;

    public function __construct(protected ShopifyGraphQlClient $client) {}

    /** @return array<string, array{title: string, variants: list<array{id: string, sku: ?string}>}> keyed by lowercased, trimmed product title */
    public function resolve(): array
    {
        $excludedTitles = collect(config('stockout.excluded_product_titles', []))
            ->map(fn (string $title) => mb_strtolower(trim($title)))
            ->all();
        $excludedVariantIds = config('stockout.excluded_variant_ids', []);
        $excludedProductTypes = collect(config('stockout.excluded_product_types', []))
            ->map(fn (string $type) => mb_strtolower(trim($type)))
            ->all();

        $map = [];
        $cursor = null;

        do {
            $result = $this->client->query(self::QUERY, ['cursor' => $cursor]);

            if ($result['errors']) {
                throw new RuntimeException('Shopify error resolving catalog eligibility: '.($result['errors'][0]['message'] ?? 'unknown error'));
            }

            $page = $result['data']['products'];

            foreach ($page['nodes'] as $product) {
                $title = trim($product['title']);
                $key = mb_strtolower($title);

                if (in_array($key, $excludedTitles, true)) {
                    continue;
                }

                if (in_array(mb_strtolower(trim((string) ($product['productType'] ?? ''))), $excludedProductTypes, true)) {
                    continue;
                }

                if ($product['status'] !== 'ACTIVE' || blank($product['onlineStoreUrl'])) {
                    continue;
                }

                $eligibleVariants = [];

                foreach ($product['variants']['nodes'] as $variant) {
                    if (! $variant['inventoryItem']['tracked']) {
                        continue;
                    }

                    if ($variant['inventoryPolicy'] !== 'DENY') {
                        continue;
                    }

                    if (in_array($variant['id'], $excludedVariantIds, true)) {
                        continue;
                    }

                    $sku = trim((string) ($variant['sku'] ?? ''));
                    $eligibleVariants[] = ['id' => $variant['id'], 'sku' => $sku !== '' ? $sku : null];
                }

                if ($eligibleVariants !== []) {
                    $map[$key] = ['title' => $title, 'variants' => $eligibleVariants];
                }
            }

            $cursor = $page['pageInfo']['hasNextPage'] ? $page['pageInfo']['endCursor'] : null;
        } while ($cursor !== null);

        return $map;
    }
}
