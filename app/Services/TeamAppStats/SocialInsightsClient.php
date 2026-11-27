<?php

namespace App\Services\TeamAppStats;

use App\Services\BioSteelTeams\BioSteelTeamsClient;
use Illuminate\Support\Facades\Http;

/**
 * BioSteel Teams' Social Insights (Admin) API — leaderboard-style reports
 * over the app's social features (posts/clips, follows), launched
 * 2026-09. Reuses BioSteelTeamsClient's own base URL and cached bearer
 * token (same account, same login flow) rather than duplicating either —
 * see that class's own token() docblock, which is deliberately public for
 * exactly this kind of reuse.
 */
class SocialInsightsClient
{
    public function __construct(protected BioSteelTeamsClient $teamsClient) {}

    /**
     * Posts/clips ranked by reactions+comments drawn during $period
     * (WEEK/TWO_WEEKS/MONTH). Null on any request failure — never an empty
     * array, so a caller can tell "no data" apart from "the request failed"
     * the same way BioSteelTeamsClient::teamsBySport() already does.
     *
     * @return list<array<string, mixed>>|null
     */
    public function topPosts(string $period = 'WEEK', int $lookbackDays = 180, int $limit = 25): ?array
    {
        return $this->get('/api/social/admin/insights/top-posts', [
            'period' => $period, 'lookbackDays' => $lookbackDays, 'limit' => $limit,
        ]);
    }

    /**
     * Users/teams ranked by followers gained during $period. Null on any
     * request failure.
     *
     * @return list<array<string, mixed>>|null
     */
    public function topFollowed(string $period = 'WEEK', int $limit = 25): ?array
    {
        return $this->get('/api/social/admin/insights/top-followed', [
            'period' => $period, 'limit' => $limit,
        ]);
    }

    /** @return list<array<string, mixed>>|null */
    protected function get(string $path, array $query): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->teamsClient->token(),
            'Accept' => 'application/json',
        ])->get(rtrim((string) config('services.biosteel_teams.base_url'), '/').$path, $query);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json('data');

        return is_array($data) ? $data : null;
    }
}
