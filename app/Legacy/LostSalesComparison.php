<?php

namespace App\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * `compare:lost-sales`: hashes every row the page reads, in both
 * databases. The history tables are compared on (product_title, day)
 * without their IDs, which differ by design; intervals and estimates keep
 * the old IDs, so they are compared whole, in ID order. Counts and hashes
 * only.
 */
final class LostSalesComparison
{
    /**
     * @return list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>
     */
    public function run(): array
    {
        $sections = [];
        foreach (LostSalesImporter::HISTORY as $table => $columns) {
            $compared = array_values(array_diff($columns, ['created_at', 'updated_at']));
            // Sorted here, byte by byte, not in SQL: the two databases can
            // sort text differently (on the server the old one ignores case
            // and this one doesn't), which made identical rows look different.
            $sections[$table] = fn (?string $connection) => RowHasher::sorted(DB::connection($connection)->table($table)
                ->get($compared)
                ->map(fn ($row) => RowHasher::normalise((array) $row))->all());
        }
        foreach (LostSalesImporter::MIRRORED as $table => $columns) {
            $sections[$table] = fn (?string $connection) => DB::connection($connection)->table($table)
                ->orderBy('id')->get($columns)
                ->map(fn ($row) => RowHasher::normalise((array) $row))->all();
        }

        $out = [];
        foreach ($sections as $name => $rows) {
            $old = $rows('legacy');
            $new = $rows(null);
            $oldHash = RowHasher::hash($old);
            $newHash = RowHasher::hash($new);
            $out[] = ['section' => $name, 'old_rows' => count($old), 'new_rows' => count($new), 'old_hash' => $oldHash, 'new_hash' => $newHash, 'match' => $oldHash === $newHash];
        }

        return $out;
    }
}
