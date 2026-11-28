<?php

namespace App\Console\Commands;

use App\Cutover\Cutover;
use App\Legacy\MirrorImports;
use Illuminate\Console\Command;

/**
 * Reads the old database through the read-only `legacy` connection and
 * prints counts only. Makes the tables an exact copy of the old app's.
 */
class LegacyImportProductsCommand extends Command
{
    protected $signature = 'legacy:import-products
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy products and sc_products from the old app, IDs preserved (the old app\'s copies win until Phase 1)';

    public function handle(): int
    {
        // Once the listings tools have moved, SC's Sync Products here owns
        // sc_products, and the old app's copy goes stale (decision 2026-12-07).
        $tables = MirrorImports::PRODUCTS;
        if (Cutover::hasMoved('listings')) {
            unset($tables['sc_products']);
            $this->line('sc_products is skipped: the listings tools have moved, so Sync Products here keeps it.');
        }

        $report = MirrorImports::run($tables, (bool) $this->option('dry-run'), array_intersect_key(MirrorImports::PRODUCTS_UNIQUE_KEYS, $tables));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
