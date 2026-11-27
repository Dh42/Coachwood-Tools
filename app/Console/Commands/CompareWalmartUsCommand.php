<?php

namespace App\Console\Commands;

use App\Legacy\WalmartUsComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Lesley runs this on the server after `legacy:import-walmart-us`. It
 * prints row counts and hashes only.
 */
class CompareWalmartUsCommand extends Command
{
    protected $signature = 'compare:walmart-us
        {--from= : First day, YYYY-MM-DD (default: 60 days before --to)}
        {--to= : Last day, YYYY-MM-DD (default: yesterday)}';

    protected $description = 'Compare the Walmart US Listing Auditor data between the old app and this one for a fixed past period';

    public function handle(WalmartUsComparison $comparison): int
    {
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now()->subDay();
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : $to->copy()->subDays(59);

        $sections = $comparison->run($from, $to);

        $this->line("Period: {$from->toDateString()} to {$to->toDateString()} (UTC). Catalog items are compared as they are now.");
        $this->table(['Section', 'Old rows', 'Matched here', 'Only here', 'Old hash', 'New hash', 'Match'], array_map(
            fn (array $s) => [$s['section'], $s['old_rows'], $s['new_rows'], $s['only_here'], $s['old_hash'], $s['new_hash'], $s['match'] ? 'yes' : 'NO'],
            $sections,
        ));

        $mismatches = count(array_filter($sections, fn (array $s) => ! $s['match']));
        if ($mismatches > 0) {
            $this->warn("{$mismatches} section(s) differ.");

            return self::FAILURE;
        }

        $this->info('Everything matches. "Only here" rows are this app\'s own runs.');

        return self::SUCCESS;
    }
}
