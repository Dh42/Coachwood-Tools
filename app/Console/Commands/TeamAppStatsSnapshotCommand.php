<?php

namespace App\Console\Commands;

use App\Models\TeamAppCategorySnapshot;
use App\Models\TeamAppSnapshot;
use App\Services\BioSteelTeams\BioSteelTeamsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly standing job: pulls current user/team totals + the sport breakdown
 * from the BioSteel Teams API and writes one snapshot bucketed to the top of
 * the hour. Ported from the legacy standalone tool's collect.php —
 * re-running within the same clock hour overwrites that hour's row (upsert
 * on captured_at), so this is safe to run more than once an hour.
 */
class TeamAppStatsSnapshotCommand extends Command
{
    protected $signature = 'team-app-stats:snapshot';

    protected $description = 'Pulls current BioSteel Teams app user/team totals and writes one hourly snapshot';

    public function handle(BioSteelTeamsClient $client): int
    {
        try {
            $users = $client->emailCount();
            $teamsBySport = $client->teamsBySport();
        } catch (\Throwable $e) {
            $this->error("BioSteel Teams API request failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($users === null || $teamsBySport === null) {
            $this->error('BioSteel Teams API returned no usable data — skipping this run.');

            return self::FAILURE;
        }

        $capturedAt = now()->startOfHour()->toDateTimeString();

        $snapshotId = DB::transaction(function () use ($capturedAt, $users, $teamsBySport) {
            DB::table('team_app_snapshots')->upsert(
                [['captured_at' => $capturedAt, 'users' => $users, 'teams' => $teamsBySport['total']]],
                ['captured_at'],
                ['users', 'teams']
            );

            $id = TeamAppSnapshot::where('captured_at', $capturedAt)->value('id');

            TeamAppCategorySnapshot::where('snapshot_id', $id)->delete();

            $rows = [];
            foreach ($teamsBySport['bySport'] as $category => $count) {
                $rows[] = ['snapshot_id' => $id, 'category' => (string) $category, 'teams' => $count];
            }

            if ($rows !== []) {
                TeamAppCategorySnapshot::insert($rows);
            }

            return $id;
        });

        $this->info(sprintf(
            'OK users=%d teams=%d categories=%d @ %s (snapshot #%d)',
            $users, $teamsBySport['total'], count($teamsBySport['bySport']), $capturedAt, $snapshotId
        ));

        return self::SUCCESS;
    }
}
