<?php

namespace App\Console\Commands;

use App\Legacy\MirrorImports;
use Illuminate\Console\Command;

/**
 * Reads the old database through the read-only `legacy` connection and
 * prints counts only. Makes `image_audits` an exact copy of the old app's.
 * Image submissions are in `listing_edits` (`legacy:import-listings`), and
 * the image files are copied separately (README).
 */
class LegacyImportImageManagerCommand extends Command
{
    protected $signature = 'legacy:import-image-manager
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy the VC Image Manager\'s audits from the old app, IDs preserved';

    public function handle(): int
    {
        $report = MirrorImports::run(MirrorImports::IMAGE_MANAGER, (bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
