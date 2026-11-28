<?php

namespace App\Legacy;

/**
 * `legacy:import-team-sponsorship` (plan section 5.7): mirrors the three
 * tables with their IDs (MirrorImports::TEAM_SPONSORSHIP), in one
 * transaction. The tables are small (under a thousand orders).
 *
 * Two of them have a second unique key: orders on `shopify_order_id`, and
 * activity on (email, team name). This app's nightly refresh can add an
 * activity row the old app later adds under another ID, so those clashes
 * are cleared first (MirrorImports::run).
 */
final class TeamSponsorshipImporter
{
    /** @var array<string, list<string>> table => its second unique key */
    private const UNIQUE_KEYS = [
        'orders' => ['shopify_order_id'],
        'team_sponsorship_activity' => ['email', 'team_name'],
    ];

    /** @return array<string, array{read: int, new: int, updated: int, removed: int}> */
    public static function run(bool $dryRun = false): array
    {
        return MirrorImports::run(MirrorImports::TEAM_SPONSORSHIP, $dryRun, self::UNIQUE_KEYS);
    }
}
