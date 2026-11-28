<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The mirrored tables, by import command. Each list is parents first.
 */
final class MirrorImports
{
    /**
     * `products` and `sc_products`: copies of the old app's until Phase 1
     * (plan section 5, "Shared tables"). The SC Listings Editor's Sync
     * Products writes `sc_products` here too, so it can add a SKU the old
     * app later adds under another ID: see PRODUCTS_UNIQUE_KEYS.
     */
    public const PRODUCTS = [
        'products' => ['id', 'asin', 'sku', 'title', 'type', 'list_price', 'cost', 'cogs', 'active', 'created_at', 'updated_at'],
        'sc_products' => ['id', 'asin', 'sku', 'title', 'status', 'synced_at', 'created_at'],
    ];

    /** @var array<string, list<string>> second unique keys of PRODUCTS tables that this app can also write */
    public const PRODUCTS_UNIQUE_KEYS = ['sc_products' => ['sku']];

    /** `vc_traffic_current`: read by CDQ for glance-view weights; a read-only copy until Phase 1 (plan section 5, "Shared tables"). */
    public const VC_TRAFFIC = [
        'vc_traffic_current' => ['id', 'dedup_key', 'asin', 'period_start', 'period_end', 'glance_views', 'lost_featured_offer_pct', 'sourceable_roos_pct', 'sell_through_rate_pct', 'pulled_at'],
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
     * Team Sponsorship and its Stats page (plan section 5.7): IDs preserved,
     * since order items point at their order by ID. The old app wins: rows
     * only here are removed. While writes are off this app creates no
     * orders of its own, so nothing of its own is lost; the activity rows
     * its nightly refresh writes are replaced by the old app's.
     */
    public const TEAM_SPONSORSHIP = [
        'orders' => ['id', 'email', 'team_name', 'shopify_order_id', 'sent_at', 'created_at', 'updated_at'],
        'order_items' => ['id', 'order_id', 'product_label', 'quantity', 'created_at', 'updated_at'],
        'team_sponsorship_activity' => ['id', 'email', 'team_name', 'team_id', 'user_id', 'emails_count', 'events_count', 'games_count', 'practices_count', 'refreshed_at', 'last_error'],
    ];

    /**
     * The VC and SC Listings Editors (plan section 5.8): IDs preserved, and
     * the old app wins. While writes are off this app submits nothing, so it
     * has no edits of its own to lose; the audits its daily run adds are
     * replaced by the old app's. `listing_edits` is shared with the VC Image
     * Manager (step 12). The attribute catalogs have no `id`; see
     * ListingsImporter.
     */
    public const LISTINGS = [
        'listing_audit_config' => ['id', 'effective_from', 'attr_slot_1', 'alias_slot_1', 'attr_slot_2', 'alias_slot_2', 'attr_slot_3', 'alias_slot_3', 'attr_slot_4', 'alias_slot_4', 'attr_slot_5', 'alias_slot_5', 'attr_slot_6', 'alias_slot_6', 'attr_slot_7', 'alias_slot_7', 'reason', 'changed_by_user_id'],
        'listing_edits' => ['id', 'product_id', 'user_id', 'asin', 'sku', 'attribute', 'old_value', 'new_value', 'submission_id', 'status', 'issues_json', 'created_at'],
        'listing_audit' => ['id', 'product_id', 'has_serving_quantity', 'has_energy_content', 'has_total_fat', 'has_total_carbohydrate', 'has_protein', 'bullet_point_count', 'has_regulatory_compliance', 'brand_code', 'manufacturer_code', 'replenishment_category', 'aplus_status', 'image_count', 'contribution_mismatch_count', 'contribution_mismatch_attrs', 'audited_attributes', 'listing_issues', 'listing_restrictions', 'fetch_error', 'checked_at'],
        'sc_listing_audit_config' => ['id', 'effective_from', 'attr_slot_1', 'alias_slot_1', 'attr_slot_2', 'alias_slot_2', 'attr_slot_3', 'alias_slot_3', 'attr_slot_4', 'alias_slot_4', 'attr_slot_5', 'alias_slot_5', 'attr_slot_6', 'alias_slot_6', 'attr_slot_7', 'alias_slot_7', 'reason', 'changed_by_user_id'],
        'sc_listing_edits' => ['id', 'product_id', 'user_id', 'asin', 'sku', 'attribute', 'old_value', 'new_value', 'submission_id', 'status', 'issues_json', 'created_at'],
        'sc_listing_audit' => ['id', 'product_id', 'has_serving_quantity', 'has_energy_content', 'has_total_fat', 'has_total_carbohydrate', 'has_protein', 'bullet_point_count', 'has_regulatory_compliance', 'brand_code', 'manufacturer_code', 'replenishment_category', 'aplus_status', 'image_count', 'contribution_mismatch_count', 'contribution_mismatch_attrs', 'audited_attributes', 'listing_issues', 'listing_restrictions', 'fetch_error', 'checked_at'],
    ];

    /**
     * The VC Image Manager (plan section 5.9): its audits, IDs preserved, and
     * the old app wins. Its submissions are in `listing_edits`, which
     * `legacy:import-listings` copies.
     */
    public const IMAGE_MANAGER = [
        'image_audits' => ['id', 'product_id', 'unattributed_variants', 'unattributed_count', 'not_live_variants', 'not_live_count', 'fetch_error', 'checked_at'],
    ];

    /** @var array<string, list<string>> keyed by `attribute_key` */
    public const LISTINGS_CATALOGS = [
        'listing_audit_attribute_catalog' => ['attribute_key', 'first_seen_at', 'last_seen_at'],
        'sc_listing_audit_attribute_catalog' => ['attribute_key', 'first_seen_at', 'last_seen_at'],
    ];

    /**
     * Mirrors the tables in one transaction; a dry run rolls it back.
     *
     * $uniqueKeys names a table's second unique key, for tables this app also
     * writes. A row here can hold a key the old app has under a different ID,
     * and the upsert on `id` would then hit that key. Such rows are removed
     * first, and the mirror brings the old app's row in: they count as
     * "removed here" and again as "new here". Keys compare trimmed and
     * lowercase, as MariaDB's collation does.
     *
     * @param  array<string, list<string>>  $tables
     * @param  array<string, list<string>>  $uniqueKeys  table => its second unique key
     * @return array<string, array{read: int, new: int, updated: int, removed: int}>
     */
    public static function run(array $tables, bool $dryRun = false, array $uniqueKeys = []): array
    {
        $legacy = DB::connection('legacy');
        $db = DB::connection();
        $db->beginTransaction();

        try {
            $cleared = [];
            foreach ($uniqueKeys as $table => $columns) {
                $cleared[$table] = self::clearClashes($legacy, $db, $table, $columns);
            }
            $report = (new TableMirror($legacy, $db))->mirror($tables);
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
