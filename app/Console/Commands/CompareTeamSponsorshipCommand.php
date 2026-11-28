<?php

namespace App\Console\Commands;

use App\Legacy\TeamSponsorshipComparison;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server after `legacy:import-team-sponsorship`.
 * It prints row counts and hashes only.
 */
class CompareTeamSponsorshipCommand extends Command
{
    protected $signature = 'compare:team-sponsorship';

    protected $description = 'Compare Team Sponsorship between the old app and this one (Stats page figures and every table)';

    public function handle(TeamSponsorshipComparison $comparison): int
    {
        $sections = $comparison->run();

        $this->table(['Section', 'Old rows', 'New rows', 'Old hash', 'New hash', 'Match'], array_map(
            fn (array $s) => [$s['section'], $s['old_rows'], $s['new_rows'], $s['old_hash'], $s['new_hash'], $s['match'] ? 'yes' : 'NO'],
            $sections,
        ));

        $mismatches = count(array_filter($sections, fn (array $s) => ! $s['match']));
        if ($mismatches > 0) {
            $this->warn("{$mismatches} section(s) differ. If a sponsorship was sent or the nightly activity refresh ran since the last import, run the import again first.");

            return self::FAILURE;
        }

        $this->info('Everything matches.');

        return self::SUCCESS;
    }
}
