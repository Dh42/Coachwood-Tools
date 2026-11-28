<?php

namespace App\Services\AspTracker;

use App\Models\AspIngestLog;
use App\Support\ExternalWrites;
use App\Support\ExternalWritesDisabled;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The one-time history load, as a resumable state machine over the latest
 * 'backfill' row in asp_ingest_log — each advance() call moves it one step,
 * so it runs fine from the scheduler (every 10 min) or by hand:
 *
 *   (none) → submitted   bulkOperationRunQuery over orders since backfill_since
 *   submitted/running    poll the BulkOperation; still going → wait
 *   → importing          COMPLETED: download the JSONL, stream it through
 *                        OrderLineMapper + SalesLineWriter, then resolve
 *                        subscription list prices. Bulk exports can't
 *                        include refund lines, so orders with a refunded
 *                        line are queued for an exact re-fetch (~13k
 *                        refunded + ~7k edited CP orders since 2024 — an
 *                        hour or two of asp:process-pending)
 *   → completed          nothing more to do (until --fresh)
 *   → failed             Shopify failed the operation, or the import threw.
 *                        Stays failed — never resubmits by itself; re-run
 *                        with --fresh once the cause is known. Except an
 *                        import that lost a database deadlock with another
 *                        ASP job: that goes back to 'running', so the next
 *                        run re-polls and imports again (writes are
 *                        idempotent), up to MAX_IMPORT_RETRIES times.
 *
 * A bulk operation sidesteps paging and rate limits entirely: 352k CP orders
 * since 2024 is one operation. Only one bulk query can run per app at a
 * time — nothing else on the CP token (Combined Shipping, Zendesk sidebar)
 * uses bulk operations.
 */
class BackfillService
{
    use DetectsConcurrencyErrors;

    private const ORDERS_PER_BATCH = 200;

    // An import killed mid-way (deploy, OOM) leaves 'importing' behind; after
    // this long without an update it's picked up again from the start (the
    // write path is idempotent, and the result URL is valid for 7 days).
    private const STALE_IMPORT_MINUTES = 180;

    private const MAX_IMPORT_RETRIES = 5;

    private const RETRY_NOTE = 'Import retried after a database deadlock';

    private const SUBMIT_MUTATION = <<<'GQL'
        mutation($query: String!) {
            bulkOperationRunQuery(query: $query) {
                bulkOperation { id status }
                userErrors { field message }
            }
        }
        GQL;

    private const POLL_QUERY = <<<'GQL'
        query($id: ID!) {
            node(id: $id) { ... on BulkOperation { id status errorCode objectCount fileSize url partialDataUrl } }
        }
        GQL;

    public function __construct(
        private readonly SalesLineWriter $writer,
        private readonly ListPriceResolver $resolver,
    ) {}

    public static function latest(string $store): ?AspIngestLog
    {
        return AspIngestLog::where('store', $store)->where('kind', 'backfill')->orderByDesc('id')->first();
    }

    /** @return array{state: string, message: string, log: ?AspIngestLog} */
    public function advance(string $store, bool $fresh = false, ?string $since = null): array
    {
        $log = self::latest($store);

        if ($fresh || $log === null) {
            return $this->submit($store, $since ?? config("asp_tracker.stores.{$store}.backfill_since"));
        }

        if ($log->status === 'importing' && $log->updated_at->lt(now()->subMinutes(self::STALE_IMPORT_MINUTES))) {
            $log->update(['status' => 'running', 'notes' => trim($log->notes."\nStale import restarted ".now()->toDateTimeString())]);
        }

        return match ($log->status) {
            'completed' => ['state' => 'completed', 'message' => "Backfill already completed {$log->finished_at} ({$log->orders_touched} orders). Use --fresh to run it again.", 'log' => $log],
            'failed' => ['state' => 'failed-earlier', 'message' => "The last backfill failed: {$log->errors} — fix the cause, then run asp:backfill {$store} --fresh.", 'log' => $log],
            'importing' => ['state' => 'importing', 'message' => 'Another run is importing the result right now.', 'log' => $log],
            default => $this->poll($store, $log),
        };
    }

    private function submit(string $store, string $since): array
    {
        // A bulk operation is a Shopify write, and only one can run per
        // store: the old app's runs until cutover (plan section 5.6).
        if (! ExternalWrites::allowed('asp-tracker')) {
            return ['state' => 'failed', 'message' => (new ExternalWritesDisabled('Shopify bulkOperationRunQuery'))->getMessage(), 'log' => null];
        }

        $client = OrderFetcher::client($store);

        $shop = $client->query('query { shop { taxesIncluded currencyCode } }');
        OrderFetcher::throwOnErrors($shop, 'shop settings');
        if ($shop['data']['shop']['taxesIncluded'] ?? false) {
            throw new RuntimeException('This store has tax-inclusive pricing (shop.taxesIncluded = true). The ASP tracker assumes tax-exclusive prices — not starting a backfill that would ingest wrong numbers.');
        }

        $result = $client->query(self::SUBMIT_MUTATION, ['query' => OrderQueries::bulkOrdersQuery($since)]);
        OrderFetcher::throwOnErrors($result, 'bulk operation submit');

        $userErrors = $result['data']['bulkOperationRunQuery']['userErrors'] ?? [];
        $operation = $result['data']['bulkOperationRunQuery']['bulkOperation'] ?? null;
        if ($userErrors !== [] || $operation === null) {
            $message = implode('; ', array_map(fn (array $e) => $e['message'], $userErrors)) ?: 'bulkOperationRunQuery returned no operation.';
            throw new RuntimeException("Shopify refused the bulk operation: {$message}");
        }

        $log = AspIngestLog::create([
            'store' => $store,
            'kind' => 'backfill',
            'status' => 'submitted',
            'bulk_operation_id' => $operation['id'],
            'since' => $since,
            'started_at' => now(),
            'notes' => 'Shop currency '.($shop['data']['shop']['currencyCode'] ?? '?').', taxesIncluded false.',
        ]);

        return ['state' => 'submitted', 'message' => "Submitted bulk operation {$operation['id']} for orders since {$since}.", 'log' => $log];
    }

    private function poll(string $store, AspIngestLog $log): array
    {
        $result = OrderFetcher::client($store)->query(self::POLL_QUERY, ['id' => $log->bulk_operation_id]);
        OrderFetcher::throwOnErrors($result, 'bulk operation status');
        $operation = $result['data']['node'] ?? null;

        if ($operation === null) {
            return $this->fail($log, "Bulk operation {$log->bulk_operation_id} not found.");
        }

        if (in_array($operation['status'], ['CREATED', 'RUNNING', 'CANCELING'], true)) {
            $log->update(['status' => 'running', 'notes' => self::replaceProgress($log->notes, "Shopify: {$operation['status']}, {$operation['objectCount']} objects so far.")]);

            return ['state' => 'running', 'message' => "Bulk operation {$operation['status']} — {$operation['objectCount']} objects so far.", 'log' => $log];
        }

        if ($operation['status'] !== 'COMPLETED') {
            return $this->fail($log, "Bulk operation {$operation['status']}".($operation['errorCode'] ? " ({$operation['errorCode']})" : '').'.');
        }

        // A store with no orders in range completes with no file at all.
        if ($operation['url'] === null) {
            $log->update(['status' => 'completed', 'finished_at' => now(), 'notes' => self::replaceProgress($log->notes, 'Completed with no orders in range.')]);

            return ['state' => 'completed', 'message' => 'Bulk operation completed with no orders in range.', 'log' => $log];
        }

        return $this->import($store, $log, $operation['url'], (int) $operation['objectCount']);
    }

    private function import(string $store, AspIngestLog $log, string $url, int $objectCount): array
    {
        $log->update(['status' => 'importing']);

        $dir = storage_path('app/asp-tracker');
        File::ensureDirectoryExists($dir);
        $path = "{$dir}/bulk-{$log->id}.jsonl";

        try {
            $download = Http::timeout(1800)->sink($path)->get($url);
            if (! $download->successful()) {
                throw new RuntimeException("Downloading the bulk result failed with HTTP {$download->status()}.");
            }

            $reader = new BulkJsonlReader;
            $mapper = new OrderLineMapper($store);
            $totals = ['orders' => 0, 'lines_changed' => 0, 'unattributed_refunds' => 0];
            $refetch = [];
            $batch = [];

            foreach ($reader->orders($path) as $order) {
                $batch[] = $mapper->map($order);
                if (count($batch) >= self::ORDERS_PER_BATCH) {
                    $this->writeBatch($batch, $totals, $refetch, $log);
                    $batch = [];
                }
            }
            $this->writeBatch($batch, $totals, $refetch, $log);

            $queued = PendingOrderProcessor::enqueue($store, array_keys($refetch + $reader->orphanOrderIds), 'backfill-refetch');

            $resolved = $this->resolver->resolve($store, retryFallbacks: true);

            $log->update([
                'status' => 'completed',
                'finished_at' => now(),
                'notes' => self::replaceProgress($log->notes, "Imported {$objectCount} objects; {$resolved} subscription list prices resolved; {$queued} orders queued for an exact re-fetch (refunds, or split across the file)."),
            ]);

            return ['state' => 'completed', 'message' => "Imported {$totals['orders']} orders ({$totals['lines_changed']} lines changed).", 'log' => $log];
        } catch (Throwable $e) {
            return $this->causedByConcurrencyError($e) ? $this->retryLater($log, $e) : $this->fail($log, $e->getMessage());
        } finally {
            File::delete($path);
        }
    }

    /** @param array<int, true> $refetch collects orders whose refunds are only estimated */
    private function writeBatch(array $batch, array &$totals, array &$refetch, AspIngestLog $log): void
    {
        if ($batch === []) {
            return;
        }

        $this->keepExactRefunds($batch, $refetch);

        foreach ($this->writer->write($batch) as $key => $value) {
            $totals[$key] += $value;
        }

        // Progress is visible on the page while a long import runs, and
        // touching updated_at keeps the stale-import check from firing.
        $log->update([
            'orders_touched' => $totals['orders'],
            'lines_changed' => $totals['lines_changed'],
            'unattributed_refunds' => $totals['unattributed_refunds'],
        ]);
    }

    /**
     * Bulk rows only estimate refund amounts. Where a line is already stored
     * with the same refunded quantity, its stored amount came from an exact
     * re-fetch — keep it rather than overwrite it with the estimate, so a
     * re-run of the backfill changes nothing. Any other order with an
     * estimated refund is queued for that exact re-fetch.
     */
    private function keepExactRefunds(array &$batch, array &$refetch): void
    {
        $lineIds = [];
        foreach ($batch as $mapped) {
            if ($mapped['refunds_estimated']) {
                array_push($lineIds, ...array_column($mapped['lines'], 'line_item_id'));
            }
        }
        if ($lineIds === []) {
            return;
        }

        $stored = DB::table('asp_sales_lines')->whereIn('line_item_id', $lineIds)
            ->get(['line_item_id', 'refunded_qty', 'refunded_amount'])->keyBy('line_item_id');

        foreach ($batch as &$mapped) {
            if (! $mapped['refunds_estimated']) {
                continue;
            }

            $exact = true;
            foreach ($mapped['lines'] as &$row) {
                if ($row['refunded_qty'] === 0) {
                    continue;
                }
                $existing = $stored[$row['line_item_id']] ?? null;
                if ($existing !== null && (int) $existing->refunded_qty === $row['refunded_qty']) {
                    $row['refunded_amount'] = round((float) $existing->refunded_amount, 2);
                    $row['content_hash'] = OrderLineMapper::hash($row);
                } else {
                    $exact = false;
                }
            }
            unset($row);

            if (! $exact) {
                $refetch[$mapped['order_id']] = true;
            }
        }
        unset($mapped);
    }

    /** Back to 'running': the next scheduled run re-polls (fresh result URL) and imports again. */
    private function retryLater(AspIngestLog $log, Throwable $e): array
    {
        $retries = substr_count((string) $log->notes, self::RETRY_NOTE);
        if ($retries >= self::MAX_IMPORT_RETRIES) {
            return $this->fail($log, "Gave up after {$retries} deadlock retries: {$e->getMessage()}");
        }

        $log->update(['status' => 'running', 'notes' => trim($log->notes."\n".self::RETRY_NOTE.' '.now()->toDateTimeString()." at {$log->orders_touched} orders.")]);

        return ['state' => 'retrying', 'message' => "The import hit a database deadlock with another ASP job after {$log->orders_touched} orders; it will pick up again on the next run.", 'log' => $log];
    }

    private function fail(AspIngestLog $log, string $message): array
    {
        $log->update(['status' => 'failed', 'finished_at' => now(), 'errors' => mb_substr($message, 0, 60000)]);

        return ['state' => 'failed', 'message' => $message, 'log' => $log];
    }

    private static function replaceProgress(?string $notes, string $progress): string
    {
        $kept = array_filter(explode("\n", (string) $notes), fn ($line) => ! str_starts_with($line, 'Progress: '));

        return implode("\n", [...$kept, "Progress: {$progress}"]);
    }
}
