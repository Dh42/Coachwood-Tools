<?php

namespace App\Console\Commands;

use App\Legacy\WalmartUsImporter;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server. It reads the old database through the
 * read-only `legacy` connection and prints counts only.
 */
class LegacyImportWalmartUsCommand extends Command
{
    protected $signature = 'legacy:import-walmart-us
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy the Walmart US Listing Auditor tables from the old app (safe to re-run)';

    public function handle(): int
    {
        $report = WalmartUsImporter::make()->run((bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
