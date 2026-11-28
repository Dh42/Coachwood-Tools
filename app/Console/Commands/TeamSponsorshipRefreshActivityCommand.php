<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\TeamSponsorshipActivity;
use App\Services\BioSteelTeams\BioSteelTeamsClient;
use App\Services\TeamSponsorship\TeamActivityClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Daily standing job: resolves and caches, per (email, team_name) pair that
 * has ever had a real sponsorship order, the BioSteel team_id/user_id and
 * email-reach/event/game/practice counts shown on the Team Sponsorship
 * Stats "Recent Sponsorships" table. See
 * create_team_sponsorship_activity_table for why this is cached rather than
 * fetched live — resolving team_id alone requires paging BioSteel's entire
 * org-wide team roster (confirmed live: up to 9 pages of 500).
 *
 * Only ever overwrites a field once this run actually got a fresh value for
 * it — a failed sub-lookup for one team leaves that team's previous good
 * values alone rather than blanking them, same principle already
 * established for CDQ and VC Birdseye's AI summary.
 */
class TeamSponsorshipRefreshActivityCommand extends Command
{
    protected $signature = 'team-sponsorship:refresh-activity';

    protected $description = 'Refreshes cached email-reach/event/game/practice counts for every team that has ever received a sponsorship order';

    /** @var array<string, ?string> lowercased email => userId, cached per run */
    private array $userIdCache = [];

    /** @var array<string, array> lowercased email => teamsForEmail() result, cached per run */
    private array $teamsForEmailCache = [];

    public function handle(BioSteelTeamsClient $client, TeamActivityClient $activityClient): int
    {
        $pairs = Order::query()->select('email', 'team_name')->distinct()->get();

        if ($pairs->isEmpty()) {
            $this->info('No sponsorship orders yet — nothing to refresh.');

            return self::SUCCESS;
        }

        $this->info("Building the org-wide team index (paging BioSteel's full team roster)...");

        try {
            $teamIndex = $this->buildTeamIndex($client);
        } catch (Throwable $e) {
            $this->error("Failed to build the BioSteel team index: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach ($pairs as $pair) {
            $this->refreshPair($client, $activityClient, $teamIndex, (string) $pair->email, (string) $pair->team_name);
        }

        $this->info("Processed {$pairs->count()} team(s).");

        return self::SUCCESS;
    }

    /** @return array<string, array<string, ?string>> lowercased email => [lowercased normalized team name => teamId] */
    private function buildTeamIndex(BioSteelTeamsClient $client): array
    {
        $index = [];

        foreach ($client->allTeamAdminEmailRows() as $row) {
            if ($row['teamName'] === null) {
                continue;
            }

            $teamKey = Str::lower(Order::normalizeTeamName($row['teamName']));

            foreach ($row['adminEmails'] as $email) {
                $index[$email][$teamKey] = $row['teamId'];
            }
        }

        return $index;
    }

    private function refreshPair(
        BioSteelTeamsClient $client,
        TeamActivityClient $activityClient,
        array $teamIndex,
        string $email,
        string $teamName
    ): void {
        $emailKey = Str::lower(trim($email));
        $teamKey = Str::lower(Order::normalizeTeamName($teamName));
        $updates = [];
        $errors = [];

        $teamId = $teamIndex[$emailKey][$teamKey] ?? null;
        if ($teamId !== null) {
            $updates['team_id'] = $teamId;
        } else {
            $errors[] = 'team_id not found in BioSteel team roster';
        }

        $userId = $this->resolveUserId($client, $email, $emailKey, $errors);
        if ($userId !== null) {
            $updates['user_id'] = $userId;
        }

        $emailsCount = $this->resolveEmailsCount($client, $email, $emailKey, $teamKey, $errors);
        if ($emailsCount !== null) {
            $updates['emails_count'] = $emailsCount;
        }

        // Only teamId is needed — a null userId falls back to a placeholder
        // inside TeamActivityClient (the endpoint ignores it, confirmed live).
        if ($teamId !== null) {
            try {
                $counts = $activityClient->counts($userId, $teamId);
                foreach (['events' => 'events_count', 'games' => 'games_count', 'practices' => 'practices_count'] as $from => $to) {
                    if ($counts[$from] !== null) {
                        $updates[$to] = $counts[$from];
                    } else {
                        $errors[] = "{$from} count request failed";
                    }
                }
            } catch (Throwable $e) {
                $errors[] = "counts request failed: {$e->getMessage()}";
            }
        }

        // refreshed_at only advances when at least one real field actually
        // got a fresh value this run — a run that resolved nothing new
        // (e.g. the team dropped out of the roster entirely) still records
        // last_error, but shouldn't look like a successful refresh happened.
        if ($updates !== []) {
            $updates['refreshed_at'] = now();
        }
        $updates['last_error'] = $errors === [] ? null : implode('; ', $errors);

        TeamSponsorshipActivity::updateOrCreate(['email' => $email, 'team_name' => $teamName], $updates);
    }

    private function resolveUserId(BioSteelTeamsClient $client, string $email, string $emailKey, array &$errors): ?string
    {
        if (! array_key_exists($emailKey, $this->userIdCache)) {
            try {
                $this->userIdCache[$emailKey] = $client->findUserIdByEmail($email);
            } catch (Throwable $e) {
                $this->userIdCache[$emailKey] = null;
                $errors[] = "user_id lookup failed: {$e->getMessage()}";

                return null;
            }
        }

        $userId = $this->userIdCache[$emailKey];
        if ($userId === null) {
            $errors[] = 'user_id not found (no unique BioSteel user match)';
        }

        return $userId;
    }

    /**
     * Real email addresses reachable through this team — see
     * TeamSponsorshipActivity::computeEmailsCount() for the formula and why
     * it lives there (single source of truth, shared with the live search
     * flow's own email_count).
     */
    private function resolveEmailsCount(BioSteelTeamsClient $client, string $email, string $emailKey, string $teamKey, array &$errors): ?int
    {
        if (! array_key_exists($emailKey, $this->teamsForEmailCache)) {
            try {
                $this->teamsForEmailCache[$emailKey] = $client->teamsForEmail($email);
            } catch (Throwable $e) {
                $this->teamsForEmailCache[$emailKey] = [];
                $errors[] = "email count lookup failed: {$e->getMessage()}";
            }
        }

        $matchedTeam = collect($this->teamsForEmailCache[$emailKey])
            ->first(fn (array $t) => Str::lower(Order::normalizeTeamName($t['teamName'] ?? '')) === $teamKey);

        return $matchedTeam === null ? null : TeamSponsorshipActivity::computeEmailsCount($matchedTeam);
    }
}
