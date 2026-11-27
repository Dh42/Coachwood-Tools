<?php

namespace App\Services\AspTracker;

use App\Models\AspIngestLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Works through asp_pending_orders: re-fetch each order, write it, drop it
 * from the queue. A failed fetch stays queued with its error and is retried
 * next run, up to MAX_ATTEMPTS, after which it stays parked (visible on the
 * page's ingest panel) instead of retrying forever.
 */
class PendingOrderProcessor
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly OrderFetcher $fetcher,
        private readonly SalesLineWriter $writer,
        private readonly ListPriceResolver $resolver,
    ) {}

    public static function enqueue(string $store, iterable $orderIds, string $reason): int
    {
        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($orderIds as $orderId) {
            $rows[] = ['store' => $store, 'order_id' => (int) $orderId, 'reason' => $reason, 'attempts' => 0, 'last_error' => null, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            // A re-queued order goes back to a clean slate (fresh attempts).
            DB::table('asp_pending_orders')->upsert($chunk, ['store', 'order_id'], ['reason', 'attempts', 'last_error', 'updated_at']);
        }

        return count($rows);
    }

    /**
     * `failed` counts every error this run. `parked` counts orders that just
     * used their last attempt, and `resolver_failed` the list-price pass:
     * those need a person. Any other failed order is still queued and gets
     * retried next run.
     *
     * @return array{processed: int, failed: int, parked: int, resolver_failed: bool, lines_changed: int, log: ?AspIngestLog}
     */
    public function process(string $store, int $limit): array
    {
        $pending = DB::table('asp_pending_orders')
            ->where('store', $store)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            return ['processed' => 0, 'failed' => 0, 'parked' => 0, 'resolver_failed' => false, 'lines_changed' => 0, 'log' => null];
        }

        $log = AspIngestLog::create(['store' => $store, 'kind' => 'pending', 'status' => 'running', 'started_at' => now()]);
        $mapper = new OrderLineMapper($store);
        $stats = ['orders' => 0, 'lines_changed' => 0, 'unattributed_refunds' => 0];
        $failed = 0;
        $parked = 0;
        $resolverFailed = false;
        $errors = [];

        foreach ($pending as $item) {
            try {
                $order = $this->fetcher->fetch($store, $item->order_id);
                // Deleted in Shopify: treat like a test order — drop its rows.
                $mapped = $order === null
                    ? ['skip' => true, 'order_id' => (int) $item->order_id, 'lines' => [], 'discounts' => [], 'unattributed_refunds' => 0, 'refunds_estimated' => false]
                    : $mapper->map($order);

                $written = $this->writer->write([$mapped]);
                foreach ($stats as $key => $value) {
                    $stats[$key] = $value + $written[$key];
                }
                DB::table('asp_pending_orders')->where('id', $item->id)->delete();
            } catch (Throwable $e) {
                $failed++;
                $attempts = $item->attempts + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $parked++;
                }
                $errors[] = "#{$item->order_id} (attempt {$attempts} of ".self::MAX_ATTEMPTS."): {$e->getMessage()}";
                DB::table('asp_pending_orders')->where('id', $item->id)->update([
                    'attempts' => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
            }
        }

        // Outside the per-order try, a failure here used to end the run with
        // the log stuck on 'running'; record it like any other error.
        try {
            $this->resolver->resolve($store);
        } catch (Throwable $e) {
            $failed++;
            $resolverFailed = true;
            $errors[] = "List prices: {$e->getMessage()}";
        }

        $log->update([
            'status' => $failed > 0 ? 'failed' : 'completed',
            'finished_at' => now(),
            'orders_touched' => $stats['orders'],
            'lines_changed' => $stats['lines_changed'],
            'unattributed_refunds' => $stats['unattributed_refunds'],
            'errors' => $errors === [] ? null : mb_substr(implode("\n", $errors), 0, 60000),
        ]);

        return ['processed' => $stats['orders'], 'failed' => $failed, 'parked' => $parked, 'resolver_failed' => $resolverFailed, 'lines_changed' => $stats['lines_changed'], 'log' => $log];
    }
}
