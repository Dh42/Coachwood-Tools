<?php

namespace App\Console\Commands;

use App\Cutover\Cutover;
use Illuminate\Console\Command;

/**
 * Re-runs every import, in the plan's step order, then every comparison
 * (plan section 6). Groups that have moved are skipped: this app owns their
 * data. Safe to run any number of times. Prints counts and hashes only, and
 * the time each step took, for the Phase 0 gate (plan section 7, item 5).
 */
class LegacySyncCommand extends Command
{
    protected $signature = 'legacy:sync
        {--dry-run : Run every import as a dry run, and skip the comparisons}
        {--group=* : Only these groups (the shared imports always run)}';

    protected $description = 'Re-run every import from the old app, then every comparison, skipping groups that have moved';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('group');
        foreach ($only as $key) {
            if (! array_key_exists($key, Cutover::groups())) {
                $this->error("Unknown group \"{$key}\". Groups: ".implode(', ', array_keys(Cutover::groups())).'.');

                return self::FAILURE;
            }
        }

        $summary = [];
        $failed = false;
        $started = microtime(true);
        $run = function (string $label, string $command) use ($dryRun, &$summary, &$failed): void {
            $this->newLine();
            $this->info("== {$command}");
            $t = microtime(true);
            $code = $this->call($command, $dryRun && str_starts_with($command, 'legacy:') ? ['--dry-run' => true] : []);
            $ok = $code === self::SUCCESS;
            $failed = $failed || ! $ok;
            $summary[] = [$label, $command, $ok ? 'ok' : (str_starts_with($command, 'compare:') ? 'DIFFERS' : 'FAILED'), number_format(microtime(true) - $t, 1)];
        };

        foreach (config('cutover.shared_imports') as $command) {
            $run('shared', $command);
        }
        foreach (Cutover::groups() as $key => $group) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }
            if (Cutover::hasMoved($key)) {
                $summary[] = [$key, '', 'moved '.Cutover::movedAt($key).', skipped', ''];

                continue;
            }
            foreach ($group['imports'] as $command) {
                $run($key, $command);
            }
            if (! $dryRun) {
                foreach ($group['compares'] as $command) {
                    $run($key, $command);
                }
            }
        }

        $this->newLine();
        $this->table(['Group', 'Command', 'Result', 'Seconds'], $summary);
        $this->line('Total: '.number_format(microtime(true) - $started, 1).' s.'.($dryRun ? ' Dry run: nothing was saved.' : ''));

        if ($failed) {
            $this->warn('Something failed or differs; see above.');

            return self::FAILURE;
        }
        $this->info('Sync complete.');

        return self::SUCCESS;
    }
}
