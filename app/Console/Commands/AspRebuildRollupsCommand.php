<?php

namespace App\Console\Commands;

use App\Services\AspTracker\DailyRollup;
use Illuminate\Console\Command;

/**
 * Rebuilds the ASP Tracker's daily rollups from asp_sales_lines. They're
 * kept current as lines are written, so this is the nightly safety net —
 * and the one-off after the rollups were first deployed, for lines written
 * before then.
 */
class AspRebuildRollupsCommand extends Command
{
    protected $signature = 'asp:rebuild-rollups {store=CP}';

    protected $description = 'Rebuilds the ASP tracker daily rollup tables from the stored sales lines';

    public function handle(DailyRollup $rollup): int
    {
        $store = strtoupper($this->argument('store'));
        if (config("asp_tracker.stores.{$store}") === null) {
            $this->error("Unknown store {$store}.");

            return self::FAILURE;
        }

        $started = microtime(true);
        $months = $rollup->rebuildAll($store);
        $this->info(sprintf('Rebuilt %d month(s) of %s rollups in %.1fs.', $months, $store, microtime(true) - $started));

        return self::SUCCESS;
    }
}
