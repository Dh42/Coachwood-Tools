<?php

namespace App\Services\AspTracker;

use Generator;
use RuntimeException;

/**
 * Streams a bulk-operation JSONL result back into whole orders in
 * OrderQueries' shape. Bulk output is flat: each Order is a line, then its
 * LineItems follow as their own lines with __parentId = the order. Shopify
 * writes children after their parent; an order is emitted when the next
 * order starts. A line item whose order was already emitted (Shopify
 * doesn't strictly promise contiguity) isn't dropped — its order id goes in
 * $orphanOrderIds so the caller can queue a normal re-fetch.
 *
 * Emitted orders have no 'refunds' key at all (the bulk query can't select
 * them), which is how OrderLineMapper knows to estimate refunds.
 */
class BulkJsonlReader
{
    /** @var array<int, true> */
    public array $orphanOrderIds = [];

    /** @return Generator<array> */
    public function orders(string $path): Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Can't open bulk result file {$path}");
        }

        $current = null;

        try {
            while (($raw = fgets($handle)) !== false) {
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }

                $node = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $parent = $node['__parentId'] ?? null;

                if ($parent === null) {
                    if ($current !== null) {
                        yield $current;
                    }
                    $current = $node + ['lineItems' => []];

                    continue;
                }

                unset($node['__parentId']);

                if ($current !== null && $parent === $current['id']) {
                    $current['lineItems'][] = $node;
                } else {
                    $this->orphanOrderIds[OrderLineMapper::gidToId($parent)] = true;
                }
            }

            if ($current !== null) {
                yield $current;
            }
        } finally {
            fclose($handle);
        }
    }
}
