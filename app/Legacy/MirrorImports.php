<?php

namespace App\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * The mirrored tables, by import command. Each list is parents first.
 */
final class MirrorImports
{
    /** `products` and `sc_products`: read-only copies until Phase 1 (plan section 5, "Shared tables"). */
    public const PRODUCTS = [
        'products' => ['id', 'asin', 'sku', 'title', 'type', 'list_price', 'cost', 'cogs', 'active', 'created_at', 'updated_at'],
        'sc_products' => ['id', 'asin', 'sku', 'title', 'status', 'synced_at', 'created_at'],
    ];

    /**
     * US Product Reviews (plan section 5.3): IDs preserved, and the old
     * app's rows replace this app's shadow rows on every run, so the two
     * apps' IDs never collide.
     */
    public const US_PRODUCT_REVIEWS = [
        'feedback_raw_snapshots' => ['id', 'snapshot_date', 'asin', 'marketplace_id', 'source_catalog', 'http_status', 'country_code', 'data_start_date', 'data_end_date', 'pulled_at', 'raw_response_gz'],
        'feedback_topics' => ['id', 'raw_snapshot_id', 'snapshot_date', 'asin', 'polarity', 'topic', 'number_of_mentions', 'occurrence_percentage', 'star_rating_impact', 'browse_node_occurrence_pct', 'browse_node_star_impact', 'parent_asin_mentions', 'parent_asin_occurrence_pct', 'parent_asin_star_impact', 'child_most_mentions_asin', 'child_most_mentions_count', 'child_least_mentions_asin', 'child_least_mentions_count'],
        'feedback_subtopics' => ['id', 'topic_id', 'subtopic', 'number_of_mentions', 'occurrence_percentage'],
        'feedback_snippets' => ['id', 'topic_id', 'subtopic_id', 'snippet'],
        'feedback_trend_raw_snapshots' => ['id', 'snapshot_date', 'asin', 'marketplace_id', 'source_catalog', 'http_status', 'pulled_at', 'raw_response_gz'],
        'feedback_topic_trends' => ['id', 'raw_trend_snapshot_id', 'snapshot_date', 'asin', 'polarity', 'topic', 'month_start', 'month_end', 'asin_occurrence_pct', 'parent_asin_occurrence_pct', 'browse_node_occurrence_pct'],
    ];

    /**
     * @param  array<string, list<string>>  $tables
     * @return array<string, array{read: int, new: int, updated: int, removed: int}>
     */
    public static function run(array $tables, bool $dryRun = false): array
    {
        $db = DB::connection();
        $db->beginTransaction();

        try {
            $report = (new TableMirror(DB::connection('legacy'), $db))->mirror($tables);
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }

        $dryRun ? $db->rollBack() : $db->commit();

        return $report;
    }
}
