<?php

namespace App\Console\Commands;

use App\Legacy\CdqImporter;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server. It reads the old database through the
 * read-only `legacy` connection and prints counts only.
 */
class LegacyImportCdqReportCommand extends Command
{
    protected $signature = 'legacy:import-cdq-report
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy the CDQ Listing Quality Report tables from the old app, IDs preserved; replaces the runs this app made itself (safe to re-run)';

    public function handle(): int
    {
        $report = CdqImporter::make()->run((bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
