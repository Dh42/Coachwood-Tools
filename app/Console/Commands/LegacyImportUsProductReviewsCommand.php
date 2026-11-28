<?php

namespace App\Console\Commands;

use App\Console\Concerns\StopsAfterCutover;
use App\Legacy\MirrorImports;
use Illuminate\Console\Command;

/**
 * Reads the old database through the read-only `legacy` connection and
 * prints counts only. Makes the tables an exact copy of the old app's.
 */
class LegacyImportUsProductReviewsCommand extends Command
{
    use StopsAfterCutover;

    protected $signature = 'legacy:import-us-product-reviews
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy the US Product Reviews tables from the old app, IDs preserved; replaces the rows this app collected itself';

    public function handle(): int
    {
        if ($this->hasMovedFromOldApp('us-product-reviews')) {
            return self::FAILURE;
        }

        $report = MirrorImports::run(MirrorImports::US_PRODUCT_REVIEWS, (bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
