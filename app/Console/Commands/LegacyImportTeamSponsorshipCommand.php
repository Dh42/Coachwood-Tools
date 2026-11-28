<?php

namespace App\Console\Commands;

use App\Console\Concerns\StopsAfterCutover;
use App\Legacy\TeamSponsorshipImporter;
use Illuminate\Console\Command;

/**
 * Reads the old database through the read-only `legacy` connection and
 * prints counts only. Makes the Team Sponsorship tables an exact copy of
 * the old app's.
 */
class LegacyImportTeamSponsorshipCommand extends Command
{
    use StopsAfterCutover;

    protected $signature = 'legacy:import-team-sponsorship
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy the Team Sponsorship orders, order items and team activity from the old app, IDs preserved';

    public function handle(): int
    {
        if ($this->hasMovedFromOldApp('team-sponsorship')) {
            return self::FAILURE;
        }

        $report = TeamSponsorshipImporter::run((bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
