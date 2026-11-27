<?php

namespace App\Console\Commands;

use App\Services\Stockout\LostSalesEstimationService;
use Illuminate\Console\Command;

/**
 * Run manually after stockout:derive-intervals and stockout:import-shopifyql-sales-history.
 * Never deletes prior runs (per the brief's "recomputed, never edited in
 * place, keep prior runs" rule) — always inserts a fresh batch tagged with
 * its own model_version/computed_at.
 */
class StockoutEstimateLostSalesCommand extends Command
{
    protected $signature = 'stockout:estimate-lost-sales';

    protected $description = 'Computes gross lost-sales estimates for every stockout interval using a trailing in-stock-day sales baseline';

    public function handle(LostSalesEstimationService $service): int
    {
        $this->info('Estimating lost sales...');

        try {
            $result = $service->estimate();
        } catch (\Throwable $e) {
            $this->error("  FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("  {$result['estimates_written']} estimate(s) written, {$result['skipped_no_baseline']} interval(s) skipped (no usable baseline), {$result['fully_substituted']} interval(s) fully covered by an RTD/PET successor (zero real lost days).");

        return self::SUCCESS;
    }
}
