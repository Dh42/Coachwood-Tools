<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Cached per-(email, team_name) BioSteel Teams API data — see the
 * create_team_sponsorship_activity_table migration for why this is cached
 * rather than fetched live. Refreshed daily by
 * team-sponsorship:refresh-activity.
 */
class TeamSponsorshipActivity extends Model
{
    public $timestamps = false;

    protected $table = 'team_sponsorship_activity';

    protected $fillable = [
        'email', 'team_name', 'team_id', 'user_id',
        'emails_count', 'events_count', 'games_count', 'practices_count',
        'refreshed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'refreshed_at' => 'datetime',
        ];
    }

    /**
     * Same lookup key shape used to build the map passed to the stats view
     * — case-insensitive on both parts, matching Order::forTeam()'s own
     * case-insensitive matching (not relying on DB collation, which differs
     * between MySQL prod and the SQLite test suite).
     */
    public static function lookupKey(string $email, string $teamName): string
    {
        return Str::lower($email).'|'.Str::lower(Order::normalizeTeamName($teamName));
    }

    /**
     * Real email addresses reachable through this team, not headcount —
     * the point of tracking sponsorships is building a marketing list, not
     * counting players. Registered players + staff + player contacts each
     * have their own login/email on the BioSteel Teams app; +1 is the
     * coach's own email (the one that requested the sponsorship). Single
     * source of truth for this formula — also used live by
     * TeamSponsorshipController::decorateTeam() when a search result is
     * first shown, so the number a coach sees during search and the number
     * that ends up cached here for the stats page never drift apart.
     *
     * @param  array<string, mixed>  $team  a raw team array from BioSteelTeamsClient::teamsForEmail()
     */
    public static function computeEmailsCount(array $team): int
    {
        $registered = (int) ($team['numberOfRegisteredPlayers'] ?? 0);
        $staff = (int) ($team['numberOfStaff'] ?? 0);
        $playerContacts = (int) ($team['numberOfPlayerContacts'] ?? 0);

        return $registered + $staff + $playerContacts + 1;
    }
}
