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
            $this->warn("{$mismatches} section(s) differ.");
            if ($result['orders'] !== []) {
                $this->line('Orders that differ, by their newest line save time (UTC)'.($result['sampled'] ? ', from a sample of the differing orders:' : ':'));
                $this->table(['What', 'Orders', 'Lines (old)', 'Lines (here)', 'Newest save (old)', 'Newest save (here)'], array_map(
                    fn (array $g) => [$g['what'], $g['orders'], $g['old_lines'], $g['new_lines'], $g['old_saved'] ?? '-', $g['new_saved'] ?? '-'],
                    $result['orders'],
                ));
                $this->line('Saves after the import started are the two apps both running: re-run the import, then compare straight after. Anything else needs a look.');
            } else {
                $this->line('The rollups follow the lines; if the old app\'s nightly rebuild hasn\'t run since a late refund, re-run the import first.');
            }

            return self::FAILURE;
        }

        $this->info('Everything matches.');

        return self::SUCCESS;
    }
}
