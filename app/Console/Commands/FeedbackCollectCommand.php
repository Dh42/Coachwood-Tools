<?php

namespace App\Console\Commands;

use App\Services\Feedback\FeedbackService;
use Illuminate\Console\Command;

/**
 * Weekly standing job: pulls Customer Feedback review topics for every ASIN
 * across both the VC (.ca) and SC (US) catalogs, against the US
 * marketplace (the only one this API and our operations both cover).
 * Always appends — never overwrites — so this is a real time series from
 * day one, same discipline as every other snapshot table in this project.
 */
class FeedbackCollectCommand extends Command
{
    protected $signature = 'feedback:collect';

    protected $description = 'Pulls Customer Feedback review topics for every ASIN across both catalogs (US marketplace)';

    public function handle(FeedbackService $service): int
    {
        $this->info('Starting US Product Reviews collection...');
        $counts = $service->runCollector(fn ($msg) => $this->info($msg));
        $this->info(sprintf('Done. 200=%d 204=%d other=%d error=%d', $counts['200'], $counts['204'], $counts['other'], $counts['error']));

        return self::SUCCESS;
    }
}
