<?php

namespace App\Services\Stockout;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reverses the 2026-09-15/09-19 "no historical ledger exists" conclusion —
 * `inventoryHistory` genuinely doesn't exist (confirmed, not reconsidered),
 * but ShopifyQL's `inventory` dataset (`shopifyqlQuery`, confirmed live
 * 2026-09-27 once `read_reports` + Level 2 Protected Customer Data access
 * were granted on the new app) returns real day-level out-of-stock data
 * back to ~2023-09-25/10-01 — everything earlier is an empty placeholder
 * row (blank product_title, 0 units, identical whether queried at 2015 or
 * 2023), confirmed by live binary search, not real history.
 *
 * Grouped by product_title only, not variant/SKU — ShopifyQL's own
 * grouping granularity, not a choice made here. `HAVING ending_inventory_units
 * <= 0` scopes the pull to stockout-relevant days only, matching the query
 * the user already had working manually through Shopify's Analytics Query
 * Editor UI — this is a query-relevance filter, not the write-time
 * aggregation the app's "store raw" rule warns against; in-stock days carry
 * no signal this tool needs.
 *
 * Chunked because a single request is capped at 1000 rows (confirmed live:
 * a 4-day window during a high-catalog period silently stopped at 1000
 * rows, 5 days short of the requested range) — matches the "split into 3-4
 * day chunks" guidance the user already worked out empirically. Each
 * chunk's rows are upserted before moving to the next, so a failure partway
 * through a long backfill loses nothing already ingested and can resume
 * from the failed chunk's own start date.
 */
class HistoricalInventoryImportService
{
    private const CHUNK_DAYS = 2;

    // Shopify's own apparent per-request cap on shopifyqlQuery's tableData
    // rows — confirmed live, not documented. A chunk landing exactly here
    // is a real signal of truncation worth surfacing, even though this
    // class doesn't attempt to auto-split further.
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
                    continue; // placeholder row outside real data range, not a real product
                }

                if (in_array(mb_strtolower($title), $excludedTitles, true)) {
                    $excluded++;

                    continue;
                }

                $batch[] = [
                    'product_title' => $title,
                    'day' => $row['day'],
                    'ending_inventory_units' => (int) $row['ending_inventory_units'],
                    'ingested_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($batch, 500) as $insertChunk) {
                DB::table('inventory_history_daily')->upsert(
                    $insertChunk,
                    ['product_title', 'day'],
                    ['ending_inventory_units', 'ingested_at', 'updated_at']
                );
            }

            $written += count($batch);
            $cursor = $chunkUntil->copy()->addDay();
        }

        return ['chunks' => $chunks, 'rows_written' => $written, 'rows_excluded' => $excluded];
    }

    /** @return list<array{day: string, product_title: string, ending_inventory_units: int}> */
    private function fetchChunk(CarbonInterface $since, CarbonInterface $until): array
    {
        $sinceStr = $since->toDateString();
        $untilStr = $until->toDateString();

        $query = <<<GQL
            query {
                shopifyqlQuery(
                    query: "FROM inventory SHOW ending_inventory_units GROUP BY product_title TIMESERIES day HAVING ending_inventory_units <= 0 SINCE {$sinceStr} UNTIL {$untilStr}"
                ) {
                    tableData { rows }
                    parseErrors
                }
            }
            GQL;

        $result = $this->client->query($query);

        if ($result['errors']) {
            throw new RuntimeException("Shopify error fetching historical inventory for {$sinceStr}..{$untilStr}: ".($result['errors'][0]['message'] ?? 'unknown error'));
        }

        $parseErrors = $result['data']['shopifyqlQuery']['parseErrors'] ?? [];

        if ($parseErrors !== []) {
            throw new RuntimeException("ShopifyQL parse error for {$sinceStr}..{$untilStr}: ".implode('; ', $parseErrors));
        }

        $rows = $result['data']['shopifyqlQuery']['tableData']['rows'] ?? [];

        if (count($rows) >= self::SUSPECTED_ROW_CAP) {
            Log::warning('Stockout historical import: chunk hit the suspected row cap, data may be truncated', [
                'since' => $sinceStr,
                'until' => $untilStr,
                'row_count' => count($rows),
            ]);
        }

        return $rows;
    }
}
