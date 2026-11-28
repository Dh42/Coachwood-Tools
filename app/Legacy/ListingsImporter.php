<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * `legacy:import-listings` (plan section 5.8): mirrors the VC and SC
 * Listings Editors' tables in one transaction (about 25,000 rows, mostly
 * audits). The six tables with an `id` go through TableMirror
 * (MirrorImports::LISTINGS). The two attribute catalogs are keyed by
 * `attribute_key` instead, so they are mirrored on that key here: upserted,
 * and keys the old app doesn't have removed.
 *
 * Run `legacy:import-platform` and `legacy:import-products` first: edits and
 * audits point at users and products by ID.
 */
final class ListingsImporter
{
    /** @return array<string, array{read: int, new: int, updated: int, removed: int}> */
    public static function run(bool $dryRun = false): array
    {
        $legacy = DB::connection('legacy');
        $db = DB::connection();
        $db->beginTransaction();

        try {
            $report = (new TableMirror($legacy, $db))->mirror(MirrorImports::LISTINGS);
            foreach (MirrorImports::LISTINGS_CATALOGS as $table => $columns) {
                $report[$table] = self::mirrorByKey($legacy, $db, $table, $columns);
            }
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }

        $dryRun ? $db->rollBack() : $db->commit();

        return $report;
    }

    /**
     * A few hundred rows at most, so read in one go.
     *
     * @param  list<string>  $columns  the key first
     * @return array{read: int, new: int, updated: int, removed: int}
     */
    private static function mirrorByKey(ConnectionInterface $legacy, ConnectionInterface $db, string $table, array $columns): array
    {
        $key = $columns[0];
        $rows = $legacy->table($table)->orderBy($key)->get($columns)->map(fn ($row) => (array) $row);
        $oldKeys = $rows->pluck($key)->all();
        $existing = array_flip($db->table($table)->pluck($key)->all());

        foreach ($rows->chunk(1000) as $chunk) {
            $db->table($table)->upsert($chunk->values()->all(), [$key], array_slice($columns, 1));
        }

        $gone = array_values(array_diff(array_keys($existing), $oldKeys));
        foreach (array_chunk($gone, 1000) as $chunk) {
            $db->table($table)->whereIn($key, $chunk)->delete();
        }

        $updated = count(array_intersect($oldKeys, array_keys($existing)));

        return ['read' => count($oldKeys), 'new' => count($oldKeys) - $updated, 'updated' => $updated, 'removed' => count($gone)];
    }
}
