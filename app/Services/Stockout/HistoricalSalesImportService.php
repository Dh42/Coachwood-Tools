<?php

namespace App\Services\Stockout;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sibling to HistoricalInventoryImportService — same ShopifyQL/chunking
 * approach, `FROM sales` instead of `FROM inventory`, no `HAVING` filter
 * (every day with any sales matters here, not just out-of-stock days).
 * Confirmed live 2026-09-28 this dataset is much lighter (~93 rows/day vs.
 * inventory's 200+) — chunked at 5 days instead of 2, still comfortably
 * under the same suspected 1000-row cap.
 *
 * Feeds Phase 4's baseline-demand calculation (a trailing average of the
 * most recent in-stock days before a stockout, cross-referenced locally
 * against inventory_history_daily) — never called live per-interval, which
 * would risk the same fixed-window rate limit `HistoricalInventoryImportService`
 * hit once already.
 */
class HistoricalSalesImportService
{
    private const CHUNK_DAYS = 5;

    private const SUSPECTED_ROW_CAP = 1000;

    public function __construct(protected ShopifyGraphQlClient $client) {}

    /** @return array{chunks: int, rows_written: int, rows_excluded: int} */
    public function import(CarbonInterface $since, CarbonInterface $until): array
    {
        $excludedTitles = collect(config('stockout.excluded_product_titles', []))
            ->map(fn (string $title) => mb_strtolower(trim($title)))
            ->all();

        $chunks = 0;
        $written = 0;
        $excluded = 0;

        $cursor = $since->copy()->startOfDay();
        $end = $until->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $chunkUntil = min($cursor->copy()->addDays(self::CHUNK_DAYS - 1), $end);

            $rows = $this->fetchChunk($cursor, $chunkUntil);
            $chunks++;

            $batch = [];
            $now = now();

            foreach ($rows as $row) {
                $title = trim((string) ($row['product_title'] ?? ''));

                if ($title === '') {
                    continue;
                }

                if (in_array(mb_strtolower($title), $excludedTitles, true)) {
                    $excluded++;

                    continue;
                }

                $batch[] = [
                    'product_title' => $title,
                    'day' => $row['day'],
                    'net_items_sold' => (int) $row['net_items_sold'],
                    'net_sales' => (float) $row['net_sales'],
                    'ingested_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($batch, 500) as $insertChunk) {
                DB::table('sales_history_daily')->upsert(
                    $insertChunk,
                    ['product_title', 'day'],
                    ['net_items_sold', 'net_sales', 'ingested_at', 'updated_at']
                );
            }

            $written += count($batch);
            $cursor = $chunkUntil->copy()->addDay();
        }

        return ['chunks' => $chunks, 'rows_written' => $written, 'rows_excluded' => $excluded];
    }

    /** @return list<array{day: string, product_title: string, net_items_sold: int, net_sales: float}> */
    private function fetchChunk(CarbonInterface $since, CarbonInterface $until): array
    {
        $sinceStr = $since->toDateString();
        $untilStr = $until->toDateString();

        $query = <<<GQL
            query {
                shopifyqlQuery(
                    query: "FROM sales SHOW net_items_sold, net_sales GROUP BY product_title TIMESERIES day SINCE {$sinceStr} UNTIL {$untilStr}"
                ) {
                    tableData { rows }
                    parseErrors
                }
            }
            GQL;

        $result = $this->client->query($query);

        if ($result['errors']) {
            throw new RuntimeException("Shopify error fetching sales history for {$sinceStr}..{$untilStr}: ".($result['errors'][0]['message'] ?? 'unknown error'));
        }

        $parseErrors = $result['data']['shopifyqlQuery']['parseErrors'] ?? [];

        if ($parseErrors !== []) {
            throw new RuntimeException("ShopifyQL parse error for {$sinceStr}..{$untilStr}: ".implode('; ', $parseErrors));
        }

        $rows = $result['data']['shopifyqlQuery']['tableData']['rows'] ?? [];

        if (count($rows) >= self::SUSPECTED_ROW_CAP) {
            Log::warning('Stockout sales history import: chunk hit the suspected row cap, data may be truncated', [
                'since' => $sinceStr,
                'until' => $untilStr,
                'row_count' => count($rows),
            ]);
        }

        return $rows;
    }
}
