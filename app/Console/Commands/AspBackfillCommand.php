<?php

namespace App\Console\Commands;

use App\Services\AspTracker\BackfillService;
use Illuminate\Console\Command;

/**
 * Advances the ASP tracker's bulk-operation backfill one step (see
 * BackfillService). On the Scheduler every 10 minutes, so after deploy it
 * submits, waits, then imports on its own; --wait keeps polling in this
 * process instead, for running it by hand over SSH.
 */
class AspBackfillCommand extends Command
{
    protected $signature = 'asp:backfill {store=CP} {--fresh : Submit a new bulk operation even if one already completed} {--since= : Override the store\'s backfill_since (YYYY-MM-DD)} {--wait : Keep polling until the backfill finishes}';

    protected $description = 'Loads Shopify order history into the ASP tracker via a bulk operation (resumable)';

    public function handle(BackfillService $service): int
    {
        $store = strtoupper($this->argument('store'));
        if (config("asp_tracker.stores.{$store}") === null) {
            $this->error("Unknown store {$store}.");

            return self::FAILURE;
        }

        $fresh = (bool) $this->option('fresh');

        do {
            $result = $service->advance($store, $fresh, $this->option('since'));
            $fresh = false;
            $this->line("[{$result['state']}] {$result['message']}");

            if ($result['state'] === 'failed') {
                return self::FAILURE;
            }

            $keepGoing = $this->option('wait') && in_array($result['state'], ['submitted', 'running', 'retrying'], true);
            if ($keepGoing) {
                sleep(20);
            }
        } while ($keepGoing);

        return self::SUCCESS;
    }
}
