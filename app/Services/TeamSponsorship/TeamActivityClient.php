<?php

namespace App\Services\TeamSponsorship;

use App\Services\BioSteelTeams\BioSteelTeamsClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Events/games/practices counts for a single BioSteel team, given the
 * team's userId (its admin's own user id, via BioSteelTeamsClient::
 * findUserIdByEmail()) and teamId (via BioSteelTeamsClient::
 * allTeamAdminEmailRows()). Reuses BioSteelTeamsClient's own admin bearer
 * token — confirmed live (this session) that the same admin token is
 * authorized for these /api/users/{userId}/... endpoints even though
 * they're outside the /api/admin/ prefix everything else in
 * BioSteelTeamsClient uses.
 *
 * The userId path segment is optional in practice: confirmed live
 * (2026-09-24) that with this admin token, the endpoints return the
 * team's full list regardless of which userId is in the path — the
 * owner's real id, an unrelated user's id, and an all-zeros UUID all
 * returned the same 33 events for the same team. That matters because
 * findUserIdByEmail() can't resolve every owner (/api/users/search
 * matches names/usernames, not emails — e.g. one real coach's address
 * returns nothing), and there's no team-only events endpoint to fall back
 * on. So a null userId falls back to PLACEHOLDER_USER_ID instead of
 * skipping the call. If BioSteel's API ever starts enforcing the userId,
 * the fallback call fails and the count degrades to null — the same
 * "no count" result as before this fallback existed, never a wrong number.
 */
class TeamActivityClient
{
    public const PLACEHOLDER_USER_ID = '00000000-0000-0000-0000-000000000000';

    protected string $baseUrl;

    public function __construct(protected BioSteelTeamsClient $client)
    {
        $this->baseUrl = rtrim((string) config('services.biosteel_teams.base_url'), '/');
    }

    /**
     * Each count is independently null if its own call failed — a partial
     * result (e.g. events succeeded, games failed) is meaningful to the
     * caller, which only overwrites whatever actually refreshed.
     *
     * @return array{events: ?int, games: ?int, practices: ?int}
     */
    public function counts(?string $userId, string $teamId): array
    {
        return [
            'events' => $this->count($userId, $teamId, 'events'),
            'games' => $this->count($userId, $teamId, 'games'),
            'practices' => $this->count($userId, $teamId, 'practices'),
        ];
    }

    /**
     * Just the events count, one call instead of three — for the live
     * search results on the main Team Sponsorship page (see
     * TeamSponsorshipController::search()), which only ever needs this one
     * number and is already paying for a live, un-cached team-roster scan
     * per search.
     */
    public function eventsCount(?string $userId, string $teamId): ?int
    {
        return $this->count($userId, $teamId, 'events');
    }

    private function count(?string $userId, string $teamId, string $kind): ?int
    {
        $userId ??= self::PLACEHOLDER_USER_ID;
        $response = $this->get("/api/users/{$userId}/teams/{$teamId}/{$kind}");

        if ($response->failed()) {
            return null;
        }

        $items = $response->json();

        return is_array($items) ? count($items) : null;
    }

    private function get(string $path): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$this->client->token(),
            'Accept' => 'application/json',
        ])->get("{$this->baseUrl}{$path}");
    }
}
