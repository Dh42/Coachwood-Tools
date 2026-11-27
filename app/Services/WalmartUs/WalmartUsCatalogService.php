<?php

namespace App\Services\WalmartUs;

use App\Models\WalmartUsItem;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pages through the full US Marketplace catalog and upserts current state
 * into walmart_us_items (not append-only — this table tracks "what does the
 * catalog look like right now", unlike walmart_us_listing_snapshot).
 */
class WalmartUsCatalogService
{
    public function __construct(protected WalmartUsApiClient $client) {}

    /**
     * Confirmed live: plain offset/limit pagination (the brief's assumed
     * cursor-based paging isn't what this endpoint actually returns — no
     * `nextCursor` ever comes back, only `totalItems`).
     *
     * @return int number of items upserted
     */
    public function syncItems(int $limit = 200): int
    {
        $count = 0;
        $offset = 0;
        $totalItems = null;

        do {
            $result = $this->client->getItems(['offset' => $offset, 'limit' => $limit]);

            if ($result['status'] !== 200) {
                throw new RuntimeException('Walmart US getItems failed (status '.$result['status'].', correlation_id '.$result['correlation_id'].'): '.json_encode($result['body']));
            }

            $items = $result['body']['ItemResponse'] ?? [];
            $totalItems = $result['body']['totalItems'] ?? count($items);

            foreach ($items as $item) {
                $this->upsertItem($item);
                $count++;
            }

            $offset += $limit;
        } while (count($items) > 0 && $offset < $totalItems);

        return $count;
    }

    protected function upsertItem(array $item): void
    {
        $sku = $item['sku'] ?? $item['SKU'] ?? null;
        if ($sku === null) {
            return;
        }

        WalmartUsItem::updateOrCreate(
            ['sku' => $sku],
            [
                'wpid' => $item['wpid'] ?? null,
                'gtin' => $item['gtin'] ?? $item['upc'] ?? null,
                'product_name' => $item['productName'] ?? null,
                'publish_status' => $item['publishedStatus'] ?? null,
                'lifecycle_status' => $item['lifecycleStatus'] ?? null,
                'price' => $item['price']['amount'] ?? null,
                'last_synced_at' => Carbon::now(),
            ]
        );
    }

    /**
     * Confirmed live: requires a status value (a bare call 400s), returns a
     * flat `{"PUBLISHED": 26}`-style single-entry map for that status.
     *
     * @return array<string, int> status => count
     */
    public function itemCountByStatus(string $status = 'PUBLISHED'): array
    {
        $result = $this->client->getItemCountByStatus($status);

        if ($result['status'] !== 200) {
            throw new RuntimeException('Walmart US getItemCountByStatus failed (status '.$result['status'].', correlation_id '.$result['correlation_id'].'): '.json_encode($result['body']));
        }

        return $result['body'] ?? [];
    }
}
