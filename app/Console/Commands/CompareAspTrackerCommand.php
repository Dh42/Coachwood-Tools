<?php

namespace App\Console\Commands;

use App\Legacy\AspTrackerComparison;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server after `legacy:import-asp-tracker`. It
 * prints row counts and hashes only.
 */
class CompareAspTrackerCommand extends Command
{
    protected $signature = 'compare:asp-tracker
        {--days=3 : Compare orders placed more than this many days ago}';

    protected $description = 'Compare the ASP Tracker (lines, discounts, rebuilt rollups) between the old app and this one';

    public function handle(AspTrackerComparison $comparison): int
    {
        $result = $comparison->run(now()->subDays(max(0, (int) $this->option('days'))));

        $this->line("Orders placed before {$result['cutoff']} UTC.");
        $this->table(['Section', 'Old rows', 'New rows', 'Old hash', 'New hash', 'Match'], array_map(
            fn (array $s) => [$s['section'], $s['old_rows'], $s['new_rows'], $s['old_hash'], $s['new_hash'], $s['match'] ? 'yes' : 'NO'],
            $result['sections'],
        ));

        $mismatches = count(array_filter($result['sections'], fn (array $s) => ! $s['match']));
        if ($mismatches > 0) {
            $this->warn("{$mismatches} section(s) differ. The rollups follow the lines; if the old app's nightly rebuild hasn't run since a late refund, re-run the import first.");

            return self::FAILURE;
        }

        $this->info('Everything matches.');

        return self::SUCCESS;
    }
}
