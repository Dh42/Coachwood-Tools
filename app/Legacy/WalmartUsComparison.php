<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `compare:walmart-us`: compares the Walmart US Listing Auditor's data in
 * both apps for a fixed past period (plan section 2, rule 5). The tool's
 * pages are read-only aggregations of these rows, so matching rows means
 * matching pages.
 *
 * Rows are matched on their natural keys (audits on sku + run_at, scores
 * on run_at). This app's own nightly runs, once it is live, have their own
 * run_at, so they are counted as "only here" rather than as a difference.
 * Reports counts and hashes only, never values.
 */
final class WalmartUsComparison
{
    private const SCORES = ['listing_quality', 'content_score', 'offer_score', 'rating_review_score', 'price_score', 'shipping_score', 'transactibility_score', 'item_defect_cnt', 'defect_ratio'];

    /**
     * @return list<array{section: string, old_rows: int, new_rows: int, only_here: int, old_hash: string, new_hash: string, match: bool}>
     */
    public function run(Carbon $from, Carbon $to): array
    {
        $old = DB::connection('legacy');
        $new = DB::connection();
        $range = [$from->copy()->startOfDay()->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()];

        return [
            $this->section('Catalog scores', fn (ConnectionInterface $db) => $db->table('walmart_us_catalog_scores')
                ->whereBetween('run_at', $range)->orderBy('run_at')
                ->get(['run_at', ...self::SCORES, 'item_count'])
                ->mapWithKeys(fn ($r) => [(string) $r->run_at => $this->normalise((array) $r)])->all(), $old, $new),
            $this->section('SKU audits', fn (ConnectionInterface $db) => $db->table('walmart_us_listing_audits as a')
                ->leftJoin('walmart_us_listing_snapshot as s', 's.id', '=', 'a.snapshot_id')
                ->whereBetween('a.run_at', $range)->orderBy('a.run_at')->orderBy('a.sku')
                ->get(['a.sku', 'a.run_at', 'a.as_of_date', ...array_map(fn ($c) => "a.$c", self::SCORES), 's.content_hash'])
                ->mapWithKeys(fn ($r) => [$r->sku."\0".$r->run_at => $this->normalise((array) $r)])->all(), $old, $new),
            $this->section('Issues', fn (ConnectionInterface $db) => $db->table('walmart_us_listing_audit_issues as i')
                ->join('walmart_us_listing_audits as a', 'a.id', '=', 'i.audit_id')
                ->whereBetween('a.run_at', $range)
                ->get(['a.sku', 'a.run_at', 'i.source', 'i.code', 'i.severity', 'i.field', 'i.message', 'i.recommended_action'])
                ->map(fn ($r) => $this->normalise((array) $r))
                ->sortBy(fn ($r) => implode("\0", $r))
                ->groupBy(fn ($r) => $r['sku']."\0".$r['run_at'])
                ->map(fn ($group) => $group->values()->all())->all(), $old, $new),
            $this->section('Catalog items', fn (ConnectionInterface $db) => $db->table('walmart_us_items')->orderBy('sku')
                ->get(['sku', 'wpid', 'gtin', 'product_name', 'publish_status', 'lifecycle_status', 'price', 'internal_sku'])
                ->mapWithKeys(fn ($r) => [$r->sku => $this->normalise((array) $r)])->all(), $old, $new),
        ];
    }

    /**
     * @param  callable(ConnectionInterface): array<string, mixed>  $rows  rows keyed by their natural key
     * @return array{section: string, old_rows: int, new_rows: int, only_here: int, old_hash: string, new_hash: string, match: bool}
     */
    private function section(string $name, callable $rows, ConnectionInterface $old, ConnectionInterface $new): array
    {
        $oldRows = $rows($old);
        $newRows = $rows($new);
        $matched = array_intersect_key($newRows, $oldRows);
        ksort($oldRows);
        ksort($matched);
        $oldHash = $this->hash($oldRows);
        $newHash = $this->hash($matched);

        return [
            'section' => $name,
            'old_rows' => count($oldRows),
            'new_rows' => count($matched),
            'only_here' => count($newRows) - count($matched),
            'old_hash' => $oldHash,
            'new_hash' => $newHash,
            'match' => $oldHash === $newHash,
        ];
    }

    /**
     * Same value, same text in both databases: every number to 4 decimal
     * places (MariaDB returns "70.0000" where SQLite returns "70"),
     * datetimes to the second.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, ?string>
     */
    private function normalise(array $row): array
    {
        return array_map(function ($value) {
            if ($value === null) {
                return null;
            }
            if (is_numeric($value)) {
                return number_format((float) $value, 4, '.', '');
            }

            return substr((string) $value, 0, 19);
        }, $row);
    }

    /** @param  array<mixed>  $rows */
    private function hash(array $rows): string
    {
        return substr(hash('sha256', json_encode($rows)), 0, 12);
    }
}
