<?php

namespace App\Console\Commands;

use App\Legacy\PlatformImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Lesley runs this on the server. It reads the old database through the
 * read-only `legacy` connection and prints counts only.
 */
class LegacyImportPlatformCommand extends Command
{
    protected $signature = 'legacy:import-platform
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy users, roles, permissions and Google Workspace domains from the old app (safe to re-run)';

    public function handle(): int
    {
        try {
            $report = PlatformImporter::make()->run((bool) $this->option('dry-run'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
