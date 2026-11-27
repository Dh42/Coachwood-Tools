<?php

namespace App\Console\Commands;

use App\Legacy\CdqComparison;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server after `legacy:import-cdq-report`.
 * It prints row counts and hashes only.
 */
class CompareCdqReportCommand extends Command
{
    protected $signature = 'compare:cdq-report';

    protected $description = 'Compare the CDQ Listing Quality Report tables between the old app and this one';

    public function handle(CdqComparison $comparison): int
    {
        $sections = $comparison->run();

        $this->table(['Section', 'Old rows', 'New rows', 'Old hash', 'New hash', 'Match'], array_map(
            fn (array $s) => [$s['section'], $s['old_rows'], $s['new_rows'], $s['old_hash'], $s['new_hash'], $s['match'] ? 'yes' : 'NO'],
            $sections,
        ));

        $mismatches = count(array_filter($sections, fn (array $s) => ! $s['match']));
        if ($mismatches > 0) {
            $this->warn("{$mismatches} section(s) differ. If either app's Sunday CDQ tasks ran after the last import, run the import again first.");

            return self::FAILURE;
        }

        $this->info('Everything matches.');

        return self::SUCCESS;
    }
}
