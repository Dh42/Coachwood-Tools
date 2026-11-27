<?php

namespace App\Services\Stockout;

use RuntimeException;

/**
 * BioSteel is phasing out RTD tetra-pack sports drinks (SKU `RTD-{CODE}-12`)
 * in favor of PET-bottle versions (SKU `{CODE}-12-PET`) — confirmed live
 * 2026-09-28: 9 real pairs exist so far (WF, MB, BR, RT, CL, GR, PL, IP,
 * ITL out of ~27 distinct RTD codes total), the rest either not yet
 * migrated or co-branded/special editions unlikely to ever get a generic
 * PET conversion. Per the user's own framing: when the old tetra SKU is
 * out of stock but its PET successor is in stock, that's not a real lost
 * sale — the customer had a substitute. Resolved live, not hardcoded,
 * since the migration is still rolling out flavor by flavor.
 *
 * Scoped strictly to SKUs starting with "RTD-" (not "CA-RTD-…" — a
 * different, Canada-specific SKU scheme the user's own description didn't
 * cover, not assumed to follow the same pattern without evidence).
 */
class RtdPetSuccessorResolver
{
    private const QUERY = <<<'GQL'
        query($cursor: String) {
            productVariants(first: 100, after: $cursor, query: "sku:RTD-* OR sku:*-PET") {
                pageInfo { hasNextPage endCursor }
                nodes {
                    sku
                    product { title }
                }
            }
        }
        GQL;

    public function __construct(protected ShopifyGraphQlClient $client) {}

    /** @return array<string, string> lowercased old product_title => successor product_title (original casing) */
    public function resolve(): array
    {
        $prefix = (string) config('stockout.rtd_successor_sku_prefix_to_strip', 'RTD-');
        $suffix = (string) config('stockout.rtd_successor_sku_suffix_to_append', '-PET');

        $petBySku = [];
        $rtdBySku = [];
        $cursor = null;

        do {
            $result = $this->client->query(self::QUERY, ['cursor' => $cursor]);

            if ($result['errors']) {
                throw new RuntimeException('Shopify error resolving RTD/PET successors: '.($result['errors'][0]['message'] ?? 'unknown error'));
            }

            $page = $result['data']['productVariants'];

            foreach ($page['nodes'] as $node) {
                $sku = trim((string) ($node['sku'] ?? ''));

                if ($sku === '') {
                    continue;
                }

                $title = trim($node['product']['title']);

                if (str_ends_with($sku, $suffix)) {
                    $petBySku[$sku] = $title;
                }

                if (str_starts_with($sku, $prefix)) {
                    $rtdBySku[$sku] = $title;
                }
            }

            $cursor = $page['pageInfo']['hasNextPage'] ? $page['pageInfo']['endCursor'] : null;
        } while ($cursor !== null);

        $map = [];

        foreach ($rtdBySku as $sku => $title) {
            $candidate = substr($sku, strlen($prefix)).$suffix;

            if (isset($petBySku[$candidate])) {
                $map[mb_strtolower($title)] = $petBySku[$candidate];
            }
        }

        return $map;
    }
}
