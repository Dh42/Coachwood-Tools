<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsStepsInOwnProcess;
use App\Cutover\Cutover;
use App\Models\ScheduledTask;
use Illuminate\Console\Command;

/**
 * Moves one cutover group from the old app (build spec section 14.3,
 * decision 2026-12-07). Lesley runs it on the server, after switching the
 * group's tools and scheduled tasks off in the old app. It runs the group's
 * imports one last time and its comparisons, and records the move only if
 * every comparison matches. From then on this app owns the group's data and
 * may write to outside systems for it.
 */
class CutoverMoveCommand extends Command
{
    use RunsStepsInOwnProcess;

    protected $signature = 'cutover:move {group : A group from cutover:status}
        {--force : Skip the confirmation (for scripts)}';

    protected $description = 'Move one group of tools from the old app: final import, comparison, then switch it over here';

    public function handle(): int
    {
        $key = (string) $this->argument('group');
        if (! array_key_exists($key, Cutover::groups())) {
            $this->error("Unknown group \"{$key}\". Groups: ".implode(', ', array_keys(Cutover::groups())).'.');

            return self::FAILURE;
        }
        $group = Cutover::group($key);
        if (Cutover::hasMoved($key)) {
            $this->info("{$group['label']} already moved, on ".Cutover::movedAt($key).' UTC.');

            return self::SUCCESS;
        }

        $this->line("Moving: {$group['label']}");
        $this->line('Before this, in the old app:');
        $this->line('  - Admin > Scheduler: switch off its tasks ('.implode(', ', $group['task_prefixes']).'*).');
        $this->line('  - Admin > Tools: switch off '.implode(', ', $group['tools']).', so nobody uses the old copy.');
        if (! $this->option('force') && ! $this->confirm('Are its tools and tasks switched off in the old app?')) {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        foreach ($group['imports'] as $command) {
            $this->newLine();
            $this->info("== {$command}");
            if ($this->runStep($command) !== self::SUCCESS) {
                $this->error("{$command} failed. Nothing was moved.");

                return self::FAILURE;
            }
        }
        foreach ($group['compares'] as $command) {
            $this->newLine();
            $this->info("== {$command}");
            if ($this->runStep($command) !== self::SUCCESS) {
                $this->error("{$command} found differences. Nothing was moved; check them (docs/context.md), then run this again.");

                return self::FAILURE;
            }
        }

        Cutover::markMoved($key);
        $this->newLine();
        $this->info("{$group['label']} now runs in this app. Its imports are switched off, and it may write to outside systems.");

        $tasks = ScheduledTask::query()->orderBy('command')->get()
            ->filter(fn (ScheduledTask $task) => Cutover::groupForTask($task->command) === $key);
        if ($tasks->isNotEmpty()) {
            $this->line('Its scheduled tasks here:');
            $this->table(['Command', 'Schedule', 'Enabled'], $tasks->map(fn (ScheduledTask $task) => [$task->command, $task->cron_expression, $task->enabled ? 'yes' : 'NO'])->values()->all());
        }

        $this->line('Next:');
        foreach (['Check the tasks above in Admin > Scheduler.', ...($group['after_move'] ?? [])] as $step) {
            $this->line("  - {$step}");
        }
        $this->line("To undo: `cutover:undo {$key}`.");

        return self::SUCCESS;
    }
}
