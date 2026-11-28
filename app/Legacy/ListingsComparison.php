<?php

namespace App\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * `compare:listings`: the figures the two pages show, worked out the same
 * way against the old database and this one, and every row of the eight
 * tables (plan section 2, rule 5). The import mirrors the tables, so right
 * after it everything should match. Counts and hashes only.
 *
 * The audit table is the largest (about 15,000 rows with JSON text), so each
 * table is hashed a chunk at a time rather than loaded whole.
 */
final class ListingsComparison
{
    private const CHUNK = 2000;

    /**
     * @return list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>
     */
    public function run(): array
    {
        $out = [];
        foreach (['VC' => '', 'SC' => 'sc_'] as $label => $prefix) {
            $out[] = $this->section("{$label} page figures", fn (?string $connection) => $this->figures($connection, $prefix));
        }
        foreach (MirrorImports::LISTINGS + MirrorImports::LISTINGS_CATALOGS as $table => $columns) {
            $out[] = $this->tableSection($table, $columns);
        }

        return $out;
    }

    /** @param  callable(?string): list<array<string, mixed>>  $rows */
    private function section(string $name, callable $rows): array
    {
        $old = $rows('legacy');
        $new = $rows(null);

        return $this->result($name, count($old), count($new), RowHasher::hash($old), RowHasher::hash($new));
    }

    /** @param  list<string>  $columns  the key first */
    private function tableSection(string $table, array $columns): array
    {
        [$oldRows, $oldHash] = $this->hashTable('legacy', $table, $columns);
        [$newRows, $newHash] = $this->hashTable(null, $table, $columns);

        return $this->result($table, $oldRows, $newRows, $oldHash, $newHash);
    }

    /**
     * @param  list<string>  $columns
     * @return array{int, string}
     */
    private function hashTable(?string $connection, string $table, array $columns): array
    {
        $context = hash_init('sha256');
        $count = 0;
        DB::connection($connection)->table($table)->select($columns)->orderBy($columns[0])
            ->chunk(self::CHUNK, function ($rows) use ($context, &$count) {
                foreach ($rows as $row) {
                    hash_update($context, json_encode(RowHasher::normalise((array) $row))."\n");
                    $count++;
                }
            });

        return [$count, substr(hash_final($context), 0, 12)];
    }

    private function result(string $name, int $oldRows, int $newRows, string $oldHash, string $newHash): array
    {
        return ['section' => $name, 'old_rows' => $oldRows, 'new_rows' => $newRows, 'old_hash' => $oldHash, 'new_hash' => $newHash, 'match' => $oldHash === $newHash];
    }

    /**
     * One row per figure, from each listing's latest audit (latest
     * checked_at, then highest ID, as the page reads it): how many were
     * audited, failed to fetch, have each A+ status, match or drift from our
     * last accepted submission, and have open Listings issues; plus the edit
     * history by status and the column settings in force. Worked out in PHP
     * so both databases give the same shape.
     *
     * @return list<array<string, mixed>>
     */
    private function figures(?string $connection, string $prefix): array
    {
        $db = DB::connection($connection);

        $latest = [];
        $audits = $db->table($prefix.'listing_audit')
            ->selectRaw('id, product_id, checked_at, aplus_status, contribution_mismatch_count, fetch_error is not null as failed, listing_issues')
            ->orderBy('id');
        foreach ($audits->cursor() as $audit) {
            $current = $latest[$audit->product_id] ?? null;
            if ($current === null || (string) $audit->checked_at >= (string) $current->checked_at) {
                $latest[$audit->product_id] = $audit;
            }
        }

        $count = fn (callable $test) => count(array_filter($latest, $test));
        $issues = fn ($audit) => $audit->listing_issues === null ? null : (json_decode((string) $audit->listing_issues, true) ?: []);
        $rows = [
            ['figure' => 'listings audited', 'value' => count($latest)],
            ['figure' => 'latest audit failed to fetch', 'value' => $count(fn ($a) => (bool) $a->failed)],
            ['figure' => 'A+ full', 'value' => $count(fn ($a) => $a->aplus_status === 'full')],
            ['figure' => 'A+ brand only', 'value' => $count(fn ($a) => $a->aplus_status === 'brand_only')],
            ['figure' => 'A+ none', 'value' => $count(fn ($a) => $a->aplus_status === 'none')],
            ['figure' => 'contribution matches', 'value' => $count(fn ($a) => $a->contribution_mismatch_count !== null && (int) $a->contribution_mismatch_count === 0)],
            ['figure' => 'contribution mismatched', 'value' => $count(fn ($a) => (int) $a->contribution_mismatch_count > 0)],
            ['figure' => 'listing issues open', 'value' => $count(fn ($a) => ! empty($issues($a)))],
            ['figure' => 'listing issues never checked', 'value' => $count(fn ($a) => $issues($a) === null)],
            ['figure' => 'last full audit', 'value' => $db->table($prefix.'listing_audit')->max('checked_at')],
        ];

        foreach ($db->table($prefix.'listing_edits')->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')->get() as $group) {
            $rows[] = ['figure' => "edits {$group->status}", 'value' => (int) $group->n];
        }

        $config = $db->table($prefix.'listing_audit_config')->orderByDesc('effective_from')->orderByDesc('id')->first();
        $rows[] = ['figure' => 'audit columns', 'value' => $config ? implode(',', array_map(fn ($i) => (string) $config->{"attr_slot_{$i}"}, range(1, 7))) : 'defaults'];

        return array_map(RowHasher::normalise(...), $rows);
    }
}
