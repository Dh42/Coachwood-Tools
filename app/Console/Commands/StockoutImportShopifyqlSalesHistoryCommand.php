<?php

namespace App\Console\Commands;

use App\Services\Stockout\HistoricalSalesImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sibling to stockout:import-shopifyql-history — same manual, post-deploy,
 * idempotent (upserts on product_title+day) usage pattern.
 */
class StockoutImportShopifyqlSalesHistoryCommand extends Command
{
    protected $signature = 'stockout:import-shopifyql-sales-history {--since=} {--until=}';

    protected $description = 'Backfills daily units/revenue from Shopify\'s ShopifyQL sales dataset into sales_history_daily';

    public function handle(HistoricalSalesImportService $service): int
    {
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : now()->startOfYear();
        $until = $this->option('until') ? Carbon::parse($this->option('until')) : now();

        if ($since->gt($until)) {
            $this->error('--since must not be after --until.');

            return self::FAILURE;
        }

        $this->info("Importing sales history from {$since->toDateString()} to {$until->toDateString()}...");

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
