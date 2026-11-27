<?php

namespace App\Console\Commands;

use App\Services\Stockout\StockoutIntervalDerivationService;
use Illuminate\Console\Command;

/**
 * Run manually after `stockout:import-shopifyql-history` (or any time the
 * catalog's eligibility might have changed — a product going unpublished,
 * a policy flip, a new exclusion). Not scheduled: cheap enough to re-run on
 * demand, and there's no value in running it more often than the
 * historical import itself changes.
 */
class StockoutDeriveIntervalsCommand extends Command
{
    protected $signature = 'stockout:derive-intervals';

    protected $description = 'Derives variant_availability_intervals from inventory_history_daily and the live catalog\'s eligibility';

    public function handle(StockoutIntervalDerivationService $service): int
    {
        $this->info('Deriving stockout intervals...');

        try {
            $result = $service->derive();
        } catch (\Throwable $e) {
            $this->error("  FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("  {$result['intervals_written']} interval(s) written across {$result['products_processed']} product(s), {$result['products_skipped']} product(s) skipped (no eligible variant).");

        return self::SUCCESS;
    }
}
