<?php

namespace Database\Seeders;

use App\Models\CdqSearchCriticalAttribute;
use Illuminate\Database\Seeder;

/**
 * My own starting sample, NOT Doug's real curated list — run manually
 * (`php artisan db:seed --class=CdqSearchCriticalAttributeSeeder`), never
 * from a migration, same insert-only/no-RefreshDatabase-corruption
 * reasoning as every other CDQ seeder.
 *
 * Selected as the subset of each real, live-synced product type schema's
 * own `required` attribute list (cdq_product_type_schema.required_json)
 * that plausibly affects search/browse discoverability, excluding fields
 * that are required for compliance/logistics reasons only and have no
 * bearing on whether the ASIN is findable: country_of_origin (customs),
 * supplier_declared_dg_hz_regulation (hazmat), batteries_required
 * (compliance). item_name (title) is deliberately excluded — it's already
 * its own explicit Grade D trigger in CdqScoringService, not part of this
 * curated list. main_image_locator isn't a real attribute path on this
 * account's schemas (images come from Catalog Items, not Listings Items
 * attributes) — MAIN_IMAGE_MISSING is CdqScoringService's own separate
 * Grade D trigger for the same reason.
 *
 * Doug should treat this as a first draft to correct, not a final answer
 * — I have no access to Amazon's actual search-ranking factors beyond
 * what's structurally required by the product type schema.
 */
class CdqSearchCriticalAttributeSeeder extends Seeder
{
    private const MARKETPLACE = 'A2EUQ1WTGCTBG2'; // amazon.ca — this account's only reachable VC marketplace, confirmed live in Phase 0

    public function run(): void
    {
        $rows = [
            // brand omitted for FOOD — not in that product type's own required list on this account.
            'DRINK_FLAVORED' => ['brand', 'bullet_point', 'product_category', 'product_subcategory', 'recommended_browse_nodes'],
            'FOOD' => ['bullet_point', 'product_category', 'product_subcategory', 'recommended_browse_nodes'],
            'HEALTH_PERSONAL_CARE' => ['brand', 'bullet_point', 'product_category', 'product_subcategory', 'recommended_browse_nodes'],
            'NUTRITIONAL_SUPPLEMENT' => ['brand', 'bullet_point', 'product_category', 'product_subcategory', 'recommended_browse_nodes'],
            'PROTEIN_DRINK' => ['brand', 'bullet_point', 'product_category', 'product_subcategory', 'recommended_browse_nodes'],
            'PROTEIN_SUPPLEMENT_POWDER' => ['brand', 'bullet_point', 'product_category', 'product_subcategory', 'recommended_browse_nodes'],
            'FLAVORED_DRINK_CONCENTRATE' => ['brand', 'bullet_point', 'product_category', 'product_subcategory', 'recommended_browse_nodes'],
        ];

        foreach ($rows as $productType => $attributes) {
            foreach ($attributes as $attributeName) {
                CdqSearchCriticalAttribute::updateOrCreate(
                    ['marketplace_id' => self::MARKETPLACE, 'product_type' => $productType, 'attribute_name' => $attributeName],
                    ['notes' => 'Starting sample (Claude) — from this product type\'s own required schema attributes, not yet reviewed by Doug.']
                );
            }
        }
    }
}
