<?php

namespace App\Legacy;

use App\Services\TeamAppStats\TeamAppStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `compare:team-app-stats`: runs BioSteel App Stats' own report
 * (TeamAppStatsService) against the old database and this one for the same
 * fixed past period, plus the social leaderboards, and reports row counts
 * and a hash per section (plan section 2, rule 5). It never returns the
 * values themselves.
 */
final class TeamAppStatsComparison
{
    /**
     * @return list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>
     */
    public function run(Carbon $from, Carbon $to): array
    {
        $sections = [];
        $old = $this->sections('legacy', $from, $to);
        $new = $this->sections(null, $from, $to);

        foreach ($old as $name => $oldRows) {
            $newRows = $new[$name];
            $oldHash = $this->hash($oldRows);
            $newHash = $this->hash($newRows);
            $sections[] = [
                'section' => $name,
                'old_rows' => count($oldRows),
                'new_rows' => count($newRows),
                'old_hash' => $oldHash,
                'new_hash' => $newHash,
                'match' => $oldHash === $newHash,
            ];
        }

        return $sections;
    }

    /** @return array<string, list<mixed>> */
    private function sections(?string $connection, Carbon $from, Carbon $to): array
    {
        $service = new TeamAppStatsService($connection);
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $ads = $service->adTotals($start, $end);
        $db = DB::connection($connection);
        $days = [$from->toDateString(), $to->toDateString()];

        return [
            'Users and teams, hourly' => $service->series($start, $end, 'raw'),
            'Users and teams, daily' => $service->series($start, $end, 'daily'),
            'Teams by sport, latest' => $service->categoryBreakdown($start, $end),
            'Ads by day' => $ads['rows']->map(fn ($r) => [
                $r->date->toDateString(), (int) $r->impressions, (int) $r->clicks, (int) $r->ad_requests,
                (int) $r->viewable_impressions, (int) $r->measurable_impressions,
            ])->all(),
            'Social top posts' => $db->table('team_app_social_post_snapshots')->whereBetween('captured_at', $days)
                ->orderBy('captured_at')->orderBy('period')->orderBy('rank')
                ->get(['captured_at', 'period', 'rank', 'post_id', 'interactions_in_period'])
                ->map(fn ($r) => [(string) $r->captured_at, $r->period, (int) $r->rank, $r->post_id, (int) $r->interactions_in_period])->all(),
            'Social top followed' => $db->table('team_app_social_follow_snapshots')->whereBetween('captured_at', $days)
                ->orderBy('captured_at')->orderBy('period')->orderBy('rank')
                ->get(['captured_at', 'period', 'rank', 'account_id', 'new_followers'])
                ->map(fn ($r) => [(string) $r->captured_at, $r->period, (int) $r->rank, $r->account_id, (int) $r->new_followers])->all(),
        ];
    }

    /** @param list<mixed> $rows */
    private function hash(array $rows): string
    {
        return substr(hash('sha256', json_encode($rows, JSON_PRESERVE_ZERO_FRACTION)), 0, 12);
    }
}
