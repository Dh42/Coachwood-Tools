<?php

namespace App\Console\Commands;

use App\Console\Concerns\StopsAfterCutover;
use App\Legacy\ListingsImporter;
use Illuminate\Console\Command;

/**
 * Reads the old database through the read-only `legacy` connection and
 * prints counts only. Makes the VC and SC Listings Editors' tables an exact
 * copy of the old app's.
 */
class LegacyImportListingsCommand extends Command
{
    use StopsAfterCutover;

    protected $signature = 'legacy:import-listings
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy the VC and SC Listings Editors\' edits, audits, column settings and attribute catalogs from the old app, IDs preserved';

    public function handle(): int
    {
        if ($this->hasMovedFromOldApp('listings')) {
            return self::FAILURE;
        }

        $report = ListingsImporter::run((bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
