<?php

namespace App\Console\Commands;

use App\Cutover\Cutover;
use Illuminate\Console\Command;

/**
 * Rolls one group back to the old app (build spec section 14.4). This app
 * stops writing for it and its imports work again. Anything written here
 * since the move is not in the old app; the next import would replace it.
 */
class CutoverUndoCommand extends Command
{
    protected $signature = 'cutover:undo {group : A group from cutover:status}
        {--force : Skip the confirmation (for scripts)}';

    protected $description = 'Move one group of tools back to the old app';

    public function handle(): int
    {
        $key = (string) $this->argument('group');
        if (! array_key_exists($key, Cutover::groups())) {
            $this->error("Unknown group \"{$key}\". Groups: ".implode(', ', array_keys(Cutover::groups())).'.');

            return self::FAILURE;
        }
        $group = Cutover::group($key);
        if (! Cutover::hasMoved($key)) {
            $this->info("{$group['label']} runs in the old app already.");

            return self::SUCCESS;
        }

        $this->warn("Anything {$group['label']} wrote here since ".Cutover::movedAt($key).' UTC is not in the old app, and the next import would replace it.');
        if (! $this->option('force') && ! $this->confirm('Move it back to the old app?')) {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        Cutover::undo($key);
        $this->info("{$group['label']} is back on the old app here: writes are off for it and its imports run again.");
        $this->line('Next, in the old app: switch its tools and tasks back on. Here: switch its tasks off in Admin > Scheduler, and point any webhooks back.');

        return self::SUCCESS;
    }
}
