<?php

namespace App\Console\Commands;

use App\Services\Stockout\HistoricalInventoryImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-time-per-range backfill, run manually — not on the scheduler, since
 * this pulls a specific historical window rather than an ongoing sweep.
 * Idempotent (upserts on product_title+day), safe to re-run over an
 * overlapping or repeated range.
 */
class StockoutImportShopifyqlHistoryCommand extends Command
{
    protected $signature = 'stockout:import-shopifyql-history {--since=} {--until=}';

    protected $description = 'Backfills historical out-of-stock days from Shopify\'s ShopifyQL inventory dataset into inventory_history_daily';

    public function handle(HistoricalInventoryImportService $service): int
    {
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : now()->startOfYear();
        $until = $this->option('until') ? Carbon::parse($this->option('until')) : now();

        if ($since->gt($until)) {
            $this->error('--since must not be after --until.');

            return self::FAILURE;
        }

        $this->info("Importing historical inventory from {$since->toDateString()} to {$until->toDateString()}...");

        try {
            $result = $service->import($since, $until);
        } catch (\Throwable $e) {
            $this->error("  FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("  {$result['chunks']} chunk(s), {$result['rows_written']} row(s) written, {$result['rows_excluded']} excluded row(s) skipped.");

        return self::SUCCESS;
    }
}
