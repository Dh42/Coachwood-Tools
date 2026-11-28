<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * `legacy:import-team-sponsorship` (plan section 5.7): mirrors the three
 * tables with their IDs (MirrorImports::TEAM_SPONSORSHIP), in one
 * transaction. The tables are small (under a thousand orders).
 *
 * Two of them have a second unique key: orders on `shopify_order_id`, and
 * activity on (email, team name), which MariaDB compares case-insensitively.
 * This app's nightly refresh can add an activity row the old app later adds
 * under another ID, and an upsert on `id` would then hit the other key. So
 * rows here that hold a key the old app has under a different ID are
 * removed first; the mirror then brings the old app's row in. They count as
 * "removed here" and again as "new here".
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
        $legacy = DB::connection('legacy');
        $db = DB::connection();
        $db->beginTransaction();

        try {
            $cleared = [];
            foreach (self::UNIQUE_KEYS as $table => $columns) {
                $cleared[$table] = self::clearClashes($legacy, $db, $table, $columns);
            }
            $report = (new TableMirror($legacy, $db))->mirror(MirrorImports::TEAM_SPONSORSHIP);
            foreach ($cleared as $table => $count) {
                $report[$table]['removed'] += $count;
            }
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }

        $dryRun ? $db->rollBack() : $db->commit();

        return $report;
    }

    /** @param  list<string>  $columns */
    private static function clearClashes(ConnectionInterface $legacy, ConnectionInterface $db, string $table, array $columns): int
    {
        $key = fn ($row) => implode("\0", array_map(fn (string $c) => mb_strtolower(trim((string) $row->{$c})), $columns));

        $oldIdByKey = [];
        foreach ($legacy->table($table)->get(['id', ...$columns]) as $row) {
            $oldIdByKey[$key($row)] = (int) $row->id;
        }

        $clashing = $db->table($table)->get(['id', ...$columns])
            ->filter(fn ($row) => isset($oldIdByKey[$key($row)]) && $oldIdByKey[$key($row)] !== (int) $row->id)
            ->pluck('id')->all();
        foreach (array_chunk($clashing, 1000) as $chunk) {
            $db->table($table)->whereIn('id', $chunk)->delete();
        }

        return count($clashing);
    }
}
