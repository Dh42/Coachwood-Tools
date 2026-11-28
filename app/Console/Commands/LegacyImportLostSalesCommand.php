<?php

namespace App\Console\Commands;

use App\Console\Concerns\StopsAfterCutover;
use App\Legacy\LostSalesImporter;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server. It reads the old database through the
 * read-only `legacy` connection and prints counts only.
 */
class LegacyImportLostSalesCommand extends Command
{
    use StopsAfterCutover;

    protected $signature = 'legacy:import-lost-sales
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy BioSteel Shopify Lost Sales history, intervals and estimates from the old app (not snapshots; safe to re-run)';

    public function handle(): int
    {
        if ($this->hasMovedFromOldApp('lost-sales')) {
            return self::FAILURE;
        }

        $report = LostSalesImporter::make()->run((bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
