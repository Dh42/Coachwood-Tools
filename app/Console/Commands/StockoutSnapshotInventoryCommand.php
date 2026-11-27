<?php

namespace App\Console\Commands;

use App\Services\Stockout\InventorySnapshotService;
use Illuminate\Console\Command;

/**
 * 15-minute backstop sweep, per the brief — catches anything a missed
 * webhook delivery would otherwise lose. Independent of the webhook path;
 * both write into the same inventory_snapshots table, tagged by `source`.
 */
class StockoutSnapshotInventoryCommand extends Command
{
    protected $signature = 'stockout:snapshot-inventory';

    protected $description = 'Sweeps current inventory quantities for every tracked variant and writes raw snapshot rows';

    public function handle(InventorySnapshotService $service): int
    {
        $this->info('Sweeping inventory levels...');

        try {
            $result = $service->sweep();
            $this->info("  {$result['pages']} page(s), {$result['rows_written']} row(s) written.");
        } catch (\Throwable $e) {
            $this->error("  FAILED: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
