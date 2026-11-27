<?php

namespace App\Console\Commands;

use App\Services\Feedback\FeedbackService;
use Illuminate\Console\Command;

/**
 * Weekly standing job, deliberately scheduled independently of
 * feedback:collect (not alongside it): pulls the 6-month monthly
 * occurrence-% trend per topic for every ASIN across both catalogs. This
 * is a SEPARATE API call per ASIN from the main topics collector — doubles
 * total Customer Feedback API volume if run on the same cadence.
 */
class FeedbackCollectTrendsCommand extends Command
{
    protected $signature = 'feedback:collect-trends';

    protected $description = 'Pulls the 6-month occurrence-% trend per topic for every ASIN across both catalogs (US marketplace)';

    public function handle(FeedbackService $service): int
    {
        $this->info('Starting US Product Reviews trend collection...');
        $counts = $service->runTrendCollector(fn ($msg) => $this->info($msg));
        $this->info(sprintf('Done. 200=%d 204=%d other=%d error=%d', $counts['200'], $counts['204'], $counts['other'], $counts['error']));

        return self::SUCCESS;
    }
}
