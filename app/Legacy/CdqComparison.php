<?php

namespace App\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * `compare:cdq-report`: hashes every row of the eight mirrored `cdq_*`
 * tables in both databases, in ID order (the import keeps the old IDs).
 * Large documents are compared by md5. `consist_checked_at` exists only
 * here and isn't compared; `cdq_llm_cache` is left out, since this app keeps
 * its own entries. Counts and hashes only.
 */
final class CdqComparison
{
    /**
     * @return list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>
     */
    public function run(): array
    {
        $out = [];
        foreach (CdqImporter::TABLES as $table => $columns) {
            $rows = fn (?string $connection) => DB::connection($connection)->table($table)->orderBy('id')->get($columns)
                ->map(function ($row) {
                    $row = (array) $row;
                    foreach (CdqImporter::LARGE_COLUMNS as $column) {
                        if (array_key_exists($column, $row) && $row[$column] !== null) {
                            $row[$column] = md5($row[$column]);
                        }
                    }

                    return RowHasher::normalise($row);
                })->all();

            $old = $rows('legacy');
            $new = $rows(null);
            $oldHash = RowHasher::hash($old);
            $newHash = RowHasher::hash($new);
            $out[] = ['section' => $table, 'old_rows' => count($old), 'new_rows' => count($new), 'old_hash' => $oldHash, 'new_hash' => $newHash, 'match' => $oldHash === $newHash];
        }

        return $out;
    }
}
