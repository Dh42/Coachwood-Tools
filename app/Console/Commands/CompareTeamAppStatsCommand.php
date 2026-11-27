<?php

namespace App\Console\Commands;

use App\Legacy\TeamAppStatsComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Lesley runs this on the server after `legacy:import-team-app-stats`. It
 * prints row counts and hashes only.
 */
class CompareTeamAppStatsCommand extends Command
{
    protected $signature = 'compare:team-app-stats
        {--from= : First day, YYYY-MM-DD (default: 30 days before --to)}
        {--to= : Last day, YYYY-MM-DD (default: yesterday)}';

    protected $description = 'Compare BioSteel App Stats between the old app and this one for a fixed past period';

    public function handle(TeamAppStatsComparison $comparison): int
    {
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now()->subDay();
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : $to->copy()->subDays(29);

        $sections = $comparison->run($from, $to);

        $this->line("Period: {$from->toDateString()} to {$to->toDateString()} (UTC)");
        $this->table(['Section', 'Old rows', 'New rows', 'Old hash', 'New hash', 'Match'], array_map(
            fn (array $s) => [$s['section'], $s['old_rows'], $s['new_rows'], $s['old_hash'], $s['new_hash'], $s['match'] ? 'yes' : 'NO'],
            $sections,
        ));

        $mismatches = count(array_filter($sections, fn (array $s) => ! $s['match']));
        if ($mismatches > 0) {
            $this->warn("{$mismatches} section(s) differ.");

            return self::FAILURE;
        }

        $this->info('Everything matches.');

        return self::SUCCESS;
    }
}
