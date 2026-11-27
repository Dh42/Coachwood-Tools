<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Copies the CDQ Listing Quality Report from the old app (plan section 5.5):
 * the eight `cdq_*` tables with IDs, as an exact copy (TableMirror), so the
 * old app's rows replace whatever this app's Sunday runs wrote. The check
 * config versions are data here, not seeds.
 *
 * Two differences from a plain mirror:
 * - `cdq_score.consist_checked_at` exists only here (found issue 13). Every
 *   score left after the copy is the old app's, whose consistency check has
 *   never run, so it is set to null.
 * - `cdq_llm_cache` is added to, never pruned: entries are keyed by content
 *   hash, model and prompt version, so this app's own Anthropic answers stay
 *   valid and aren't paid for again after each import.
 *
 * Reports counts only.
 */
final class CdqImporter
{
    /** Parents first. */
    public const TABLES = [
        'cdq_product_type_schema' => ['id', 'product_type', 'marketplace_id', 'schema_version', 'required_json', 'recommended_json', 'raw_schema', 'fetched_at', 'created_at', 'updated_at'],
        'cdq_listing_snapshot' => ['id', 'asin', 'sku', 'vendor_code', 'marketplace_id', 'product_type', 'parent_asin', 'content_hash', 'catalog_json', 'attributes_json', 'main_image_hash', 'aplus_json', 'issues_json', 'issues_checked_at', 'captured_at'],
        'cdq_check_config_version' => ['id', 'effective_from', 'reason', 'changed_by'],
        'cdq_check_config' => ['id', 'version_id', 'check_code', 'dimension', 'points', 'per_instance', 'instance_cap', 'enabled', 'notes'],
        'cdq_defect' => ['id', 'snapshot_id', 'dimension', 'check_code', 'severity', 'points', 'attribute_path', 'detail_json'],
        'cdq_score' => ['id', 'snapshot_id', 'check_config_version_id', 'attr_score', 'text_score', 'image_score', 'aplus_score', 'consist_score', 'total_score', 'grade', 'scored_at'],
        'cdq_search_critical_attribute' => ['id', 'marketplace_id', 'product_type', 'attribute_name', 'notes'],
        'cdq_title_length_band' => ['id', 'marketplace_id', 'product_type', 'min_length', 'max_length', 'notes'],
    ];

    /** Columns holding large documents: compared by md5. */
    public const LARGE_COLUMNS = ['required_json', 'recommended_json', 'raw_schema', 'catalog_json', 'attributes_json', 'aplus_json', 'issues_json'];

    /** A product type schema can be several hundred KB, a snapshot tens of KB. */
    private const CHUNK_SIZES = ['cdq_product_type_schema' => 5, 'cdq_listing_snapshot' => 100];

    private const CACHE_COLUMNS = ['content_hash', 'model', 'prompt_version', 'response_json', 'created_at'];

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
        $this->db->beginTransaction();

        try {
            $report = (new TableMirror($this->legacy, $this->db, $this->pauseMilliseconds, self::CHUNK_SIZES))->mirror(self::TABLES);
            $this->db->table('cdq_score')->whereNotNull('consist_checked_at')->update(['consist_checked_at' => null]);
            $report['cdq_llm_cache'] = $this->addCache();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        $dryRun ? $this->db->rollBack() : $this->db->commit();

        return $report;
    }

    /** @return array{read: int, new: int, updated: int, removed: int} */
    private function addCache(): array
    {
        $read = 0;
        $new = 0;
        $this->legacy->table('cdq_llm_cache')->select(self::CACHE_COLUMNS)->orderBy('content_hash')->orderBy('model')->orderBy('prompt_version')
            ->chunk(100, function ($rows) use (&$read, &$new) {
                $read += $rows->count();
                $new += $this->db->table('cdq_llm_cache')->insertOrIgnore($rows->map(fn ($row) => (array) $row)->all());
            });

        return ['read' => $read, 'new' => $new, 'updated' => 0, 'removed' => 0];
    }
}
