<?php

namespace App\Console\Commands;

use App\Console\Concerns\StopsAfterCutover;
use App\Legacy\TeamAppStatsImporter;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server. It reads the old database through the
 * read-only `legacy` connection and prints counts only.
 */
class LegacyImportTeamAppStatsCommand extends Command
{
    use StopsAfterCutover;

    protected $signature = 'legacy:import-team-app-stats
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy BioSteel App Stats snapshots, ads and social leaderboards from the old app (safe to re-run)';

    public function handle(): int
    {
        if ($this->hasMovedFromOldApp('team-app-stats')) {
            return self::FAILURE;
        }

        $report = TeamAppStatsImporter::make()->run((bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
