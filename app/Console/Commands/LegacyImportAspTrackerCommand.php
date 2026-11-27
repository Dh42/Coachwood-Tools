<?php

namespace App\Console\Commands;

use App\Legacy\AspTrackerImporter;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server. It reads the old database through the
 * read-only `legacy` connection and prints counts only. It takes several
 * minutes: about 1.4 million lines, 1,000 orders per batch with a pause.
 */
class LegacyImportAspTrackerCommand extends Command
{
    protected $signature = 'legacy:import-asp-tracker
        {--dry-run : Read and count everything, write nothing}';

    protected $description = 'Copy the ASP Tracker (both stores) from the old app, order by order, then rebuild the daily rollups (safe to re-run)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $shown = 0;
        $report = AspTrackerImporter::make()->run($dryRun, function (int $lines) use (&$shown) {
            if ($lines - $shown >= 100000) {
                $this->line(sprintf('  %s lines read…', number_format($lines)));
                $shown = $lines;
            }
        });

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($dryRun ? 'Dry run: nothing was saved.' : 'Import complete. Daily rollups rebuilt for both stores.');

        return self::SUCCESS;
    }
}
