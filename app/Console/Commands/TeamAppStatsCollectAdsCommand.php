<?php

namespace App\Console\Commands;

use App\Services\TeamAppStats\AdManagerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs 3x/day (see the registration migration): pulls yesterday's Google Ad
 * Manager metrics and upserts that day's row. Ported from the legacy
 * standalone tool's collect_ads.php — safe (and worthwhile) to run more than
 * once a day, since Ad Manager revises a day's numbers for hours afterward;
 * each re-run just refreshes the row with a more accurate value.
 */
class TeamAppStatsCollectAdsCommand extends Command
{
    protected $signature = 'team-app-stats:collect-ads';

    protected $description = "Pulls yesterday's Google Ad Manager metrics for the BioSteel Teams app and upserts that day's row";

    public function handle(AdManagerClient $client): int
    {
        try {
            $stats = $client->fetchYesterdayStats();
        } catch (\Throwable $e) {
            $this->error("Google Ad Manager request failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($stats === null) {
            $this->error('Google Ad Manager returned no usable data — skipping this run.');

            return self::FAILURE;
        }

        DB::table('team_app_ad_snapshots')->upsert(
            [$stats],
            ['date'],
            ['impressions', 'clicks', 'ad_requests', 'viewable_impressions', 'measurable_impressions']
        );

        $this->info(sprintf(
            'OK date=%s impressions=%s clicks=%s ad_requests=%s viewable=%s measurable=%s',
            $stats['date'], $stats['impressions'], $stats['clicks'],
            $stats['ad_requests'], $stats['viewable_impressions'], $stats['measurable_impressions']
        ));

        return self::SUCCESS;
    }
}
