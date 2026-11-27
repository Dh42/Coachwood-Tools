<?php

namespace App\Console\Commands;

use App\Models\AspIngestLog;
use App\Services\AspTracker\PendingOrderProcessor;
use Illuminate\Console\Command;

/**
 * Every minute: re-fetches orders queued by webhooks / the nightly catch-up
 * and upserts their lines. Stands in for a queue worker, which production
 * doesn't run.
 */
class AspProcessPendingCommand extends Command
{
    protected $signature = 'asp:process-pending {store=CP} {--limit= : Max orders this run (default asp_tracker.pending_batch_size)}';

    protected $description = 'Re-fetches queued Shopify orders into the ASP tracker';

    // Per-minute runs would otherwise grow the log by ~1,400 rows a day.
    private const KEEP_PENDING_LOGS_DAYS = 30;

    private const PRUNE_BATCH = 1000;

    public function handle(PendingOrderProcessor $processor): int
    {
        $store = strtoupper($this->argument('store'));
        if (config("asp_tracker.stores.{$store}") === null) {
            $this->error("Unknown store {$store}.");

            return self::FAILURE;
        }

        $limit = (int) ($this->option('limit') ?: config('asp_tracker.pending_batch_size', 200));
        $result = $processor->process($store, $limit);

        $this->pruneOldPendingLogs();

        if ($result['log'] === null) {
            $this->line('Nothing queued.');

            return self::SUCCESS;
        }

        $this->line("Processed {$result['processed']} orders, {$result['lines_changed']} lines changed, {$result['failed']} failed.");
        if ($result['failed'] === 0) {
            return self::SUCCESS;
        }

        // Only fail (which emails every admin) when something needs a
        // person: an order out of attempts, or the list-price pass. An order
        // that failed on a network blip is still queued for the next run.
        if ($result['parked'] > 0 || $result['resolver_failed']) {
            $this->error($result['log']->errors);

            return self::FAILURE;
        }

        $this->warn("Still queued, will retry next run:\n{$result['log']->errors}");

        return self::SUCCESS;
    }

    /**
     * Uses the (kind, started_at) index. In batches so a backlog never holds
     * one long delete on the shared server's disk (2026-11-16 incident).
     */
    private function pruneOldPendingLogs(): void
    {
        $cutoff = now()->subDays(self::KEEP_PENDING_LOGS_DAYS);

        do {
            $deleted = AspIngestLog::where('kind', 'pending')
                ->where('started_at', '<', $cutoff)
                ->limit(self::PRUNE_BATCH)
                ->delete();
        } while ($deleted === self::PRUNE_BATCH);
    }
}
