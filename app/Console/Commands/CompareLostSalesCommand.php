<?php

namespace App\Console\Commands;

use App\Legacy\LostSalesComparison;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server after `legacy:import-lost-sales`.
 * It prints row counts and hashes only.
 */
class CompareLostSalesCommand extends Command
{
    protected $signature = 'compare:lost-sales';

    protected $description = 'Compare BioSteel Shopify Lost Sales between the old app and this one (every table the page reads)';

    public function handle(LostSalesComparison $comparison): int
    {
        $sections = $comparison->run();

        $this->table(['Section', 'Old rows', 'New rows', 'Old hash', 'New hash', 'Match'], array_map(
            fn (array $s) => [$s['section'], $s['old_rows'], $s['new_rows'], $s['old_hash'], $s['new_hash'], $s['match'] ? 'yes' : 'NO'],
            $sections,
        ));

        $mismatches = count(array_filter($sections, fn (array $s) => ! $s['match']));
        if ($mismatches > 0) {
            $this->warn("{$mismatches} section(s) differ. If someone ran stockout:derive-intervals or stockout:estimate-lost-sales here, or the old app's ran after the last import, run the import again first.");

            return self::FAILURE;
        }

        $this->info('Everything matches.');

        return self::SUCCESS;
    }
}
