<?php

namespace App\Console\Commands;

use App\Legacy\ListingsComparison;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server after `legacy:import-listings`. It prints
 * row counts and hashes only.
 */
class CompareListingsCommand extends Command
{
    protected $signature = 'compare:listings';

    protected $description = 'Compare the VC and SC Listings Editors between the old app and this one (page figures and every table)';

    public function handle(ListingsComparison $comparison): int
    {
        $sections = $comparison->run();

        $this->table(['Section', 'Old rows', 'New rows', 'Old hash', 'New hash', 'Match'], array_map(
            fn (array $s) => [$s['section'], $s['old_rows'], $s['new_rows'], $s['old_hash'], $s['new_hash'], $s['match'] ? 'yes' : 'NO'],
            $sections,
        ));

        $mismatches = count(array_filter($sections, fn (array $s) => ! $s['match']));
        if ($mismatches > 0) {
            $this->warn("{$mismatches} section(s) differ. Either app's daily audit (08:00 UTC in the old app, 08:30 here) or an edit since the last import changes these, so run the import again first.");

            return self::FAILURE;
        }

        $this->info('Everything matches.');

        return self::SUCCESS;
    }
}
