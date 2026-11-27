<?php

namespace App\Console\Commands;

use App\Models\AspIngestLog;
use App\Services\AspTracker\OrderFetcher;
use App\Services\AspTracker\OrderLineMapper;
use App\Services\AspTracker\PendingOrderProcessor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Nightly safety net for missed or failed webhooks: queues every order
 * updated in the last N hours. Only ids are listed here (cheap); the
 * actual re-fetch is asp:process-pending's job, same as for webhooks.
 */
class AspCatchUpCommand extends Command
{
    protected $signature = 'asp:catch-up {store=CP} {--hours=48}';

    protected $description = 'Queues recently updated Shopify orders for an ASP tracker re-fetch';

    private const LIST_QUERY = <<<'GQL'
        query($query: String!, $after: String) {
            orders(first: 250, after: $after, query: $query) {
                pageInfo { hasNextPage endCursor }
                nodes { id }
            }
        }
        GQL;

    public function handle(): int
    {
        $store = strtoupper($this->argument('store'));
        if (config("asp_tracker.stores.{$store}") === null) {
            $this->error("Unknown store {$store}.");

            return self::FAILURE;
        }

        $since = now()->subHours((int) $this->option('hours'))->utc()->format('Y-m-d\TH:i:s\Z');
        $log = AspIngestLog::create(['store' => $store, 'kind' => 'catch-up', 'status' => 'running', 'started_at' => now()]);

        try {
            $client = OrderFetcher::client($store);
            $ids = [];
            $after = null;
            do {
                $result = $client->query(self::LIST_QUERY, ['query' => "updated_at:>='{$since}'", 'after' => $after]);
                OrderFetcher::throwOnErrors($result, 'recently updated orders');
                foreach ($result['data']['orders']['nodes'] as $node) {
                    $ids[] = OrderLineMapper::gidToId($node['id']);
                }
                $page = $result['data']['orders']['pageInfo'];
                $after = $page['endCursor'];
            } while ($page['hasNextPage']);

            $queued = PendingOrderProcessor::enqueue($store, $ids, 'catch-up');
            $log->update(['status' => 'completed', 'finished_at' => now(), 'orders_touched' => $queued, 'notes' => "Queued orders updated since {$since}."]);
            $this->line("Queued {$queued} orders updated since {$since}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'finished_at' => now(), 'errors' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
