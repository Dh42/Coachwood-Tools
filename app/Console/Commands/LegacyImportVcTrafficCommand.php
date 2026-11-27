<?php

namespace App\Console\Commands;

use App\Legacy\MirrorImports;
use Illuminate\Console\Command;

/**
 * Reads the old database through the read-only `legacy` connection and
 * prints counts only. Makes the tables an exact copy of the old app's.
 */
class LegacyImportVcTrafficCommand extends Command
{
    protected $signature = 'legacy:import-vc-traffic
        {--dry-run : Do the whole import, report it, then roll it back}';

    protected $description = 'Copy vc_traffic_current from the old app, IDs preserved (a read-only copy for CDQ until Phase 1)';

    public function handle(): int
    {
        $report = MirrorImports::run(MirrorImports::VC_TRAFFIC, (bool) $this->option('dry-run'));

        $this->table(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], collect($report)->map(
            fn (array $counts, string $table) => [$table, $counts['read'], $counts['new'], $counts['updated'], $counts['removed']],
        )->values()->all());

        $this->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Import complete.');

        return self::SUCCESS;
    }
}
