<?php

namespace App\Services\Stockout;

use App\Models\InventorySnapshot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 1 raw capture, two independent paths per the brief: a 15-minute
 * cron sweep (sweep(), this class) and a webhook backstop
 * (recordWebhookEvent(), also this class — same "write raw, don't
 * aggregate" rule applies to both).
 *
 * Sweep query shape and cost confirmed live (2026-09-15) against the real
 * BioSteel store: a 100-variant page costs ~65-130 points against a
 * 20,000-point/1000-per-second bucket, ~0.5s wall time per page. At 1,981
 * variants (confirmed live count) that's ~20 pages, comfortably inside a
 * 15-minute cadence with no throttling risk. `product { publishedOnCurrentPublication }`
 * was tried and is NOT queryable on this token (`read_product_listings`
 * scope missing) — omitted here; "published to Online Store" filtering is
 * deferred to Phase 3 read-time once that scope exists, same as the
 * location-scoping deferral below.
 */
class InventorySnapshotService
{
    private const SWEEP_QUERY = <<<'GQL'
        query($cursor: String, $states: [String!]!) {
            productVariants(first: 100, after: $cursor) {
                pageInfo { hasNextPage endCursor }
                edges {
                    node {
                        id
                        inventoryItem {
                            id
                            tracked
                            inventoryLevels(first: 5) {
                                edges {
                                    node {
                                        location { id }
                                        quantities(names: $states) { name quantity }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GQL;

    public function __construct(protected ShopifyGraphQlClient $client) {}

    /** @return array{pages: int, rows_written: int} */
    public function sweep(): array
    {
        $states = config('stockout.tracked_states');
        $capturedAt = now();
        $cursor = null;
        $pages = 0;
        $rows = [];
        $itemVariants = [];

        do {
            $result = $this->client->query(self::SWEEP_QUERY, ['cursor' => $cursor, 'states' => $states]);

            if ($result['errors']) {
                throw new RuntimeException('Shopify error during inventory sweep: '.($result['errors'][0]['message'] ?? 'unknown error'));
            }

            $page = $result['data']['productVariants'] ?? null;
            if ($page === null) {
                throw new RuntimeException('Shopify returned no productVariants page during inventory sweep.');
            }

            foreach ($page['edges'] as $edge) {
                $node = $edge['node'];
                $variantId = $node['id'];
                $item = $node['inventoryItem'];

                if (! $item['tracked']) {
                    continue;
                }

                $itemVariants[$item['id']] = [
                    'inventory_item_id' => $item['id'],
                    'variant_id' => $variantId,
                    'created_at' => $capturedAt,
                    'updated_at' => $capturedAt,
                ];

                foreach ($item['inventoryLevels']['edges'] ?? [] as $levelEdge) {
                    $level = $levelEdge['node'];
                    $locationId = $level['location']['id'];

                    foreach ($level['quantities'] as $q) {
                        $rows[] = [
                            'inventory_item_id' => $item['id'],
                            'variant_id' => $variantId,
                            'location_id' => $locationId,
                            'state' => $q['name'],
                            'quantity' => $q['quantity'],
                            'captured_at' => $capturedAt,
                            'source' => 'cron',
                            'created_at' => $capturedAt,
                            'updated_at' => $capturedAt,
                        ];
                    }
                }
            }

            $pages++;
            $cursor = $page['pageInfo']['hasNextPage'] ? $page['pageInfo']['endCursor'] : null;
        } while ($cursor !== null);

        foreach (array_chunk($rows, 500) as $chunk) {
            InventorySnapshot::insert($chunk);
        }

        foreach (array_chunk(array_values($itemVariants), 500) as $chunk) {
            DB::table('inventory_item_variants')->upsert($chunk, ['inventory_item_id'], ['variant_id', 'updated_at']);
        }

        return ['pages' => $pages, 'rows_written' => count($rows)];
    }

    /**
     * Webhook payload only carries inventory_item_id + available (Shopify's
     * long-stable classic REST webhook shape for inventory_levels/update —
     * confirmed via the WebhookSubscriptionInput schema being the classic
     * JSON/XML format, not a GraphQL-query-defined payload; the specific
     * field names are inherited from Shopify's documented, years-stable
     * REST webhook shape rather than independently live-fired, since
     * triggering a real one requires an actual inventory change).
     *
     * variant_id isn't in the payload, so it comes from inventory_item_variants
     * (a primary-key read). This runs once per webhook, often many at once,
     * so it must stay cheap: the old "newest snapshot with a variant" lookup
     * here sorted the item's whole history on disk and took the shared server
     * down on 2026-11-16. That lookup is kept only as a one-time fallback for
     * an item the sweep hasn't mapped yet (index inv_snap_item_time_variant
     * makes it an index read), and its answer is saved. A brand-new variant's
     * first webhook before any sweep still gets null, as before.
     */
    public function recordWebhookEvent(string $inventoryItemId, string $locationId, int $available, \DateTimeInterface $capturedAt): InventorySnapshot
    {
        $variantId = $this->variantForItem($inventoryItemId);

        return InventorySnapshot::create([
            'inventory_item_id' => $inventoryItemId,
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'state' => 'available',
            'quantity' => $available,
            'captured_at' => $capturedAt,
            'source' => 'webhook',
        ]);
    }

    private function variantForItem(string $inventoryItemId): ?string
    {
        $variantId = DB::table('inventory_item_variants')
            ->where('inventory_item_id', $inventoryItemId)
            ->value('variant_id');

        if ($variantId !== null) {
            return $variantId;
        }

        $variantId = InventorySnapshot::where('inventory_item_id', $inventoryItemId)
            ->whereNotNull('variant_id')
            ->latest('captured_at')
            ->value('variant_id');

        if ($variantId !== null) {
            DB::table('inventory_item_variants')->insertOrIgnore([
                'inventory_item_id' => $inventoryItemId,
                'variant_id' => $variantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $variantId;
    }
}
