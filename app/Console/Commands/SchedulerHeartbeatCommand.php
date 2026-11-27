<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Proves the scheduler pipeline is wired up end to end';

    public function handle(): int
    {
        $this->info('Scheduler heartbeat at '.now()->toDateTimeString());

        return self::SUCCESS;
    }
}
