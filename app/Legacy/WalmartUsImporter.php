<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies Walmart US Listing Auditor's six tables from the old app (plan
 * section 5.2). Safe to run again; the old app's data wins.
 *
 * - walmart_us_items: upserted on sku
 * - walmart_us_listing_snapshot: upserted on (sku, content_hash)
 * - walmart_us_listing_audits: upserted on (sku, run_at), snapshot re-mapped
 * - walmart_us_listing_audit_issues: each imported audit's issues brought in
 *   line with the old app's, re-mapped to the new audit ID
 * - walmart_us_catalog_scores: matched on run_at
 * - walmart_us_audit_params: matched on effective_from; `changed_by` kept
 *   when that user exists here (user IDs are preserved)
 *
 * IDs are this app's own: nothing outside these tables points at them.
 * Audits and scores this app recorded itself (its own nightly runs have a
 * different run_at) are left alone. Reports counts only.
 */
final class WalmartUsImporter
{
    private const CHUNK = 500;

    private const ITEM_COLUMNS = ['sku', 'wpid', 'gtin', 'product_name', 'publish_status', 'lifecycle_status', 'price', 'internal_sku', 'last_synced_at'];

    private const SCORE_COLUMNS = ['listing_quality', 'content_score', 'offer_score', 'rating_review_score', 'price_score', 'shipping_score', 'transactibility_score', 'item_defect_cnt', 'defect_ratio'];

    private const PARAM_COLUMNS = ['effective_from', 'min_title_length', 'max_title_length', 'min_image_count', 'min_image_resolution_px', 'min_description_length', 'min_bullet_count', 'price_guardrail_pct', 'require_shipping_template', 'exclude_trending_items', 'exclude_wfs_items', 'reason', 'changed_by'];

    private const ISSUE_COLUMNS = ['source', 'code', 'severity', 'field', 'message', 'recommended_action'];

    /** @var array<string, array{read: int, new: int, updated: int, removed: int}> */
    private array $report = [];

    public function __construct(
        private readonly ConnectionInterface $legacy,
        private readonly ConnectionInterface $db,
        private readonly int $pauseMilliseconds = 250,
    ) {}

    public static function make(): self
    {
        return new self(DB::connection('legacy'), DB::connection());
    }

    /** @return array<string, array{read: int, new: int, updated: int, removed: int}> */
    public function run(bool $dryRun = false): array
    {
        $this->report = [];
        $this->db->beginTransaction();

        try {
            $this->upsertOn('walmart_us_items', self::ITEM_COLUMNS, ['sku']);
            $snapshotIds = $this->importSnapshots();
            $auditIds = $this->importAudits($snapshotIds);
            $this->importIssues($auditIds);
            $this->matchOn('walmart_us_catalog_scores', ['id', 'run_at', ...self::SCORE_COLUMNS, 'item_count'], 'run_at');
            $this->importParams();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        $dryRun ? $this->db->rollBack() : $this->db->commit();

        return $this->report;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $uniqueBy
     */
    private function upsertOn(string $table, array $columns, array $uniqueBy): void
    {
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        $this->eachChunk($table, $columns, function (Collection $rows) use ($table, $columns, $uniqueBy, &$report) {
            $values = $rows->map(fn ($r) => (array) $r)->all();
            $existing = 0;
            foreach ($values as $row) {
                $existing += $this->db->table($table)->where(array_intersect_key($row, array_flip($uniqueBy)))->exists() ? 1 : 0;
            }
            $this->db->table($table)->upsert($values, $uniqueBy, array_values(array_diff($columns, $uniqueBy)));

            $report['read'] += count($values);
            $report['updated'] += $existing;
            $report['new'] += count($values) - $existing;
        });

        $this->report[$table] = $report;
    }

    /** @return array<int, int> old snapshot ID => new snapshot ID */
    private function importSnapshots(): array
    {
        $columns = ['sku', 'wpid', 'content_hash', 'item_json', 'listing_quality_json', 'captured_at'];
        $map = [];
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        $this->eachChunk('walmart_us_listing_snapshot', ['id', ...$columns], function (Collection $rows) use ($columns, &$map, &$report) {
            foreach ($rows as $row) {
                $key = ['sku' => $row->sku, 'content_hash' => $row->content_hash];
                $values = array_intersect_key((array) $row, array_flip($columns));
                $id = $this->db->table('walmart_us_listing_snapshot')->where($key)->value('id');
                if ($id) {
                    $this->db->table('walmart_us_listing_snapshot')->where('id', $id)->update($values);
                    $report['updated']++;
                } else {
                    $id = $this->db->table('walmart_us_listing_snapshot')->insertGetId($values);
                    $report['new']++;
                }
                $map[(int) $row->id] = (int) $id;
                $report['read']++;
            }
        });

        $this->report['walmart_us_listing_snapshot'] = $report;

        return $map;
    }

    /**
     * @param  array<int, int>  $snapshotIds
     * @return array<int, int> old audit ID => new audit ID
     */
    private function importAudits(array $snapshotIds): array
    {
        $columns = ['sku', 'as_of_date', 'run_at', 'snapshot_id', ...self::SCORE_COLUMNS, 'our_rule_score'];
        $map = [];
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        $this->eachChunk('walmart_us_listing_audits', ['id', ...$columns], function (Collection $rows) use ($columns, $snapshotIds, &$map, &$report) {
            foreach ($rows as $row) {
                $values = array_intersect_key((array) $row, array_flip($columns));
                $values['snapshot_id'] = $row->snapshot_id !== null ? ($snapshotIds[(int) $row->snapshot_id] ?? null) : null;
                $key = ['sku' => $row->sku, 'run_at' => $row->run_at];
                $id = $this->db->table('walmart_us_listing_audits')->where($key)->value('id');
                if ($id) {
                    $this->db->table('walmart_us_listing_audits')->where('id', $id)->update($values);
                    $report['updated']++;
                } else {
                    $id = $this->db->table('walmart_us_listing_audits')->insertGetId($values);
                    $report['new']++;
                }
                $map[(int) $row->id] = (int) $id;
                $report['read']++;
            }
        });

        $this->report['walmart_us_listing_audits'] = $report;

        return $map;
    }

    /** @param  array<int, int>  $auditIds  old audit ID => new audit ID */
    private function importIssues(array $auditIds): void
    {
        $key = fn (array $row) => implode("\0", array_map(fn ($v) => (string) $v, $row));
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        foreach (array_chunk(array_keys($auditIds), self::CHUNK) as $oldIds) {
            $wanted = $this->legacy->table('walmart_us_listing_audit_issues')->whereIn('audit_id', $oldIds)->orderBy('id')
                ->get(['audit_id', ...self::ISSUE_COLUMNS])
                ->map(fn ($r) => ['audit_id' => $auditIds[(int) $r->audit_id]] + array_intersect_key((array) $r, array_flip(self::ISSUE_COLUMNS)))
                ->keyBy($key);

            $newIds = array_map(fn (int $id) => $auditIds[$id], $oldIds);
            $here = $this->db->table('walmart_us_listing_audit_issues')->whereIn('audit_id', $newIds)
                ->get(['id', 'audit_id', ...self::ISSUE_COLUMNS])
                ->keyBy(fn ($r) => $key(['audit_id' => (int) $r->audit_id] + array_intersect_key((array) $r, array_flip(self::ISSUE_COLUMNS))));

            $this->db->table('walmart_us_listing_audit_issues')->whereIn('id', $here->diffKeys($wanted)->pluck('id'))->delete();
            foreach ($wanted->diffKeys($here)->values()->chunk(self::CHUNK) as $chunk) {
                $this->db->table('walmart_us_listing_audit_issues')->insert($chunk->values()->all());
            }

            $report['read'] += $wanted->count();
            $report['new'] += $wanted->diffKeys($here)->count();
            $report['removed'] += $here->diffKeys($wanted)->count();
            $this->pause();
        }

        $this->report['walmart_us_listing_audit_issues'] = $report;
    }

    /** @param  list<string>  $columns */
    private function matchOn(string $table, array $columns, string $keyColumn): void
    {
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        $this->eachChunk($table, $columns, function (Collection $rows) use ($table, $keyColumn, &$report) {
            foreach ($rows as $row) {
                $values = array_diff_key((array) $row, ['id' => true]);
                $query = $this->db->table($table)->where($keyColumn, $values[$keyColumn]);
                if ($query->exists()) {
                    $query->update($values);
                    $report['updated']++;
                } else {
                    $this->db->table($table)->insert($values);
                    $report['new']++;
                }
                $report['read']++;
            }
        });

        $this->report[$table] = $report;
    }

    private function importParams(): void
    {
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        foreach ($this->legacy->table('walmart_us_audit_params')->orderBy('id')->get(self::PARAM_COLUMNS) as $row) {
            $values = (array) $row;
            if ($values['changed_by'] !== null && ! $this->db->table('users')->where('id', $values['changed_by'])->exists()) {
                $values['changed_by'] = null;
            }
            $query = $this->db->table('walmart_us_audit_params')->where('effective_from', $values['effective_from']);
            if ($query->exists()) {
                $query->update($values);
                $report['updated']++;
            } else {
                $this->db->table('walmart_us_audit_params')->insert($values);
                $report['new']++;
            }
            $report['read']++;
        }

        $this->report['walmart_us_audit_params'] = $report;
    }

    /** @param  list<string>  $columns */
    private function eachChunk(string $table, array $columns, callable $callback): void
    {
        $order = in_array('id', $columns, true) ? 'id' : $columns[0];
        $first = true;

        $this->legacy->table($table)->select($columns)->orderBy($order)->chunk(self::CHUNK, function (Collection $rows) use ($callback, &$first) {
            if (! $first) {
                $this->pause();
            }
            $first = false;
            $callback($rows);
        });
    }

    private function pause(): void
    {
        if ($this->pauseMilliseconds > 0) {
            usleep($this->pauseMilliseconds * 1000);
        }
    }
}
