<?php

namespace App\Console\Commands;

use App\Cutover\Cutover;
use Illuminate\Console\Command;

class CutoverStatusCommand extends Command
{
    protected $signature = 'cutover:status';

    protected $description = 'List the cutover groups and which have moved from the old app';

    public function handle(): int
    {
        $this->table(['Group', 'Tools', 'Where it runs', 'Moved (UTC)'], collect(Cutover::groups())->map(fn (array $group, string $key) => [
            $key,
            $group['label'],
            Cutover::hasMoved($key) ? 'this app' : 'old app',
            Cutover::movedAt($key) ?? '',
        ])->values()->all());

        return self::SUCCESS;
    }
}
