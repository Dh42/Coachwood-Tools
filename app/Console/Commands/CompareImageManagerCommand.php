<?php

namespace App\Console\Commands;

use App\Legacy\ImageManagerComparison;
use Illuminate\Console\Command;

/**
 * Lesley runs this on the server after `legacy:import-image-manager`. It
 * prints row counts and hashes only.
 */
class CompareImageManagerCommand extends Command
{
    protected $signature = 'compare:image-manager';

    protected $description = 'Compare the VC Image Manager between the old app and this one (page figures, audits, and the image files on disk)';

    public function handle(ImageManagerComparison $comparison): int
    {
        $sections = $comparison->run();

        $this->table(['Section', 'Old rows', 'New rows', 'Old hash', 'New hash', 'Match'], array_map(
            fn (array $s) => [$s['section'], $s['old_rows'], $s['new_rows'], $s['old_hash'], $s['new_hash'], $s['match'] ? 'yes' : 'NO'],
            $sections,
        ));

        $mismatches = count(array_filter($sections, fn (array $s) => ! $s['match']));
        if ($mismatches > 0) {
            $this->warn("{$mismatches} section(s) differ. An audit in either app since the last import changes the first two, so run the import again first. For the image files, \"Old rows\" counts the files the old app's accepted submissions point at and \"New rows\" those found here; copy the missing ones (README).");

            return self::FAILURE;
        }

        $this->info('Everything matches.');

        return self::SUCCESS;
    }
}
