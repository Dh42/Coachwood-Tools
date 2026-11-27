<?php

namespace App\Services\BioSteelTeams;

use App\Models\Order;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BioSteelTeamsClient
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.biosteel_teams.base_url'), '/');
        $this->username = (string) config('services.biosteel_teams.username');
        $this->password = (string) config('services.biosteel_teams.password');
    }

    /**
     * Returns every team associated with the given email, following the
     * same pagination and dual response-shape handling as the original
     * standalone tool.
     */
    public function teamsForEmail(string $email): array
    {
        $token = $this->token();
        $url = "{$this->baseUrl}/api/admin/teams/all";

        $response = $this->post($url, $token, $email);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch teams for {$email}. HTTP {$response->status()}.");
        }

        $data = $response->json();
        $teams = [];

        if (is_array($data) && isset($data['content']) && is_array($data['content'])) {
            $teams = $data['content'];
            $page = 1;

            while (($data['last'] ?? true) === false && $page < 100) {
                $response = $this->post("{$url}?page={$page}&size=50", $token, $email);

                if ($response->failed()) {
                    break;
                }

                $data = $response->json();

                if (is_array($data) && isset($data['content']) && is_array($data['content'])) {
                    $teams = array_merge($teams, $data['content']);
                }

                $page++;
            }
        } elseif (is_array($data)) {
            $teams = $data;
        }

        return $teams;
    }

    protected function post(string $url, string $token, string $email): Response
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ])->post($url, ['email' => $email]);
    }

    /**
     * Total registered users, for BioSteel App Stats' hourly snapshot. Null
     * on any request failure (never zero) so the caller skips the snapshot
     * rather than recording a false zero — same reasoning as the legacy
     * standalone tool's fetch_stats().
     */
    public function emailCount(): ?int
    {
        $response = $this->get('/api/admin/users/email-count');

        if ($response->failed()) {
            return null;
        }

        $count = $response->json('data');

        return is_numeric($count) ? (int) $count : null;
    }

    /**
     * Total teams plus the per-sport breakdown, for BioSteel App Stats'
     * hourly snapshot. Null on any request failure.
     *
     * @return array{total: int, bySport: array<string, int>}|null
     */
    public function teamsBySport(): ?array
    {
        $response = $this->get('/api/admin/teams/count');

        if ($response->failed()) {
            return null;
        }

        $total = $response->json('data.totalTeams');
        $bySport = $response->json('data.teamsBySport');

        if (! is_numeric($total) || ! is_array($bySport)) {
            return null;
        }

        return [
            'total' => (int) $total,
            'bySport' => array_map('intval', $bySport),
        ];
    }

    protected function get(string $path, array $query = []): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$this->token(),
            'Accept' => 'application/json',
        ])->get("{$this->baseUrl}{$path}", $query);
    }

    /**
     * Every row of /api/admin/teams/sport-type-admin-emails, unfiltered
     * (teamId, teamName, sportType, adminEmails per team), paged fully in
     * one pass. Used by the daily team-sponsorship:refresh-activity batch
     * job to build one full team_id index per run, rather than re-paging
     * the whole ~4,300-team roster per team the way sportTypesForEmail()
     * does per search — that method is left untouched (already tested,
     * used live on every search) since its per-email inline filtering is a
     * different access pattern than this batch one.
     *
     * @return list<array{teamId: ?string, teamName: ?string, sportType: ?string, adminEmails: array<string>}>
     */
    public function allTeamAdminEmailRows(): array
    {
        $token = $this->token();
        $rows = [];
        $page = 0;

        do {
            $response = Http::withHeaders(['Authorization' => "Bearer {$token}"])
                ->get("{$this->baseUrl}/api/admin/teams/sport-type-admin-emails", ['page' => $page, 'size' => 500]);

            if ($response->failed()) {
                break;
            }

            $data = $response->json();

            foreach ($data['content'] ?? [] as $row) {
                $rows[] = [
                    'teamId' => $row['teamId'] ?? null,
                    'teamName' => $row['teamName'] ?? null,
                    'sportType' => $row['sportType'] ?? null,
                    'adminEmails' => array_map(fn ($e) => trim(mb_strtolower((string) $e)), $row['adminEmails'] ?? []),
                ];
            }

            $last = $data['metadata']['last'] ?? true;
            $page++;
        } while (! $last && $page < 100);

        return $rows;
    }

    /**
     * Resolves a single BioSteel userId for the given email via
     * /api/users/search — not under /api/admin/ like the rest of this
     * client, but confirmed live that the same admin bearer token is
     * authorized for it. Null when zero or more-than-one candidate comes
     * back: UserSearchDto has no email field to disambiguate multiple hits
     * against, so an ambiguous match is treated the same cautious way this
     * class already treats team-name collisions elsewhere — not confirmed,
     * don't guess.
     */
    public function findUserIdByEmail(string $email): ?string
    {
        $response = $this->get('/api/users/search', ['q' => $email, 'size' => 5]);

        if ($response->failed()) {
            return null;
        }

        $results = $response->json();

        if (! is_array($results) || count($results) !== 1) {
            return null;
        }

        $userId = $results[0]['userId'] ?? null;

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    /**
     * Maps this email's teams to their sport type. `/api/admin/teams/all` (used by
     * teamsForEmail()) is scoped by email but its TeamsInfoResponseDTO doesn't include
     * sportType at all (confirmed against the live OpenAPI schema at
     * /swagger-ui/index.html and against live data). The only endpoint that exposes
     * sportType — `/api/admin/teams/sport-type-admin-emails` — isn't scoped by email at
     * all; it pages through every team org-wide (~4,300 teams) alongside each team's
     * admin emails, so the email match has to happen client-side here. Matching by team
     * name alone against that full roster isn't safe — about 4% of team names collide
     * across different owners — so this only ever considers rows whose own adminEmails
     * already contains the searched email, which is inherently collision-free.
     *
     * @return array<string, ?string> team name (normalized, lowercased) => sport type
     */
    public function sportTypesForEmail(string $email): array
    {
        $token = $this->token();
        $email = trim(mb_strtolower($email));
        $sportTypes = [];
        $page = 0;

        do {
            $response = Http::withHeaders(['Authorization' => "Bearer {$token}"])
                ->get("{$this->baseUrl}/api/admin/teams/sport-type-admin-emails", ['page' => $page, 'size' => 500]);

            if ($response->failed()) {
                break;
            }

            $data = $response->json();

            foreach ($data['content'] ?? [] as $row) {
                $adminEmails = array_map(fn ($e) => trim(mb_strtolower((string) $e)), $row['adminEmails'] ?? []);

                if (in_array($email, $adminEmails, true) && ! empty($row['teamName'])) {
                    $key = mb_strtolower(Order::normalizeTeamName($row['teamName']));
                    $sportTypes[$key] = $row['sportType'] ?? null;
                }
            }

            $last = $data['metadata']['last'] ?? true;
            $page++;
        } while (! $last && $page < 100);

        return $sportTypes;
    }

    /**
     * The bearer token comes back in the response HEADER, not the JSON
     * body — this is the "very specific" quirk of this API. Cached briefly
     * purely to avoid a login round-trip on every request. Public so other
     * services needing this same admin token (e.g.
     * App\Services\TeamSponsorship\TeamActivityClient, for endpoints outside
     * this class's own method set) don't duplicate the login flow.
     */
    public function token(): string
    {
        return Cache::remember('biosteel_teams_api_token', now()->addMinutes(10), function () {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/api/users/login", [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if ($response->failed()) {
                throw new RuntimeException("BioSteel Teams login failed. HTTP {$response->status()}.");
            }

            $authHeader = $response->header('Authorization');

            if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
                throw new RuntimeException('BioSteel Teams login did not return a bearer token.');
            }

            return trim(substr($authHeader, 7));
        });
    }
}
