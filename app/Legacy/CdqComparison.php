<?php

namespace App\Legacy;

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
        $md5Large = function (array $row): array {
            foreach (CdqImporter::LARGE_COLUMNS as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    $row[$column] = md5($row[$column]);
                }
            }

            return $row;
        };

        foreach (CdqImporter::TABLES as $table => $columns) {
            // A chunk at a time: cdq_defect alone has about 85,000 rows.
            [$oldRows, $oldHash] = RowHasher::hashTable('legacy', $table, $columns, $md5Large);
            [$newRows, $newHash] = RowHasher::hashTable(null, $table, $columns, $md5Large);
            $out[] = ['section' => $table, 'old_rows' => $oldRows, 'new_rows' => $newRows, 'old_hash' => $oldHash, 'new_hash' => $newHash, 'match' => $oldHash === $newHash];
        }

        return $out;
    }
}
