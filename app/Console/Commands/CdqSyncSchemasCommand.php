<?php

namespace App\Console\Commands;

use App\Models\CdqListingSnapshot;
use App\Models\CdqProductTypeSchema;
use App\Models\Product;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\ListingChannel;
use Illuminate\Console\Command;

/**
 * CDQ Phase 1 — reuses SpApiClient::getProductTypeSchema() as-is (already
 * proven in production via the VC listing editor's nutrition/attribute
 * form, already cached ~24h) rather than adding a second schema-fetching
 * path. Persists Required (schema's own `required` array, verbatim) and
 * Recommended (every other top-level property — confirmed live there's no
 * Required/Recommended/Preferred tier anywhere in this API; propertyGroups
 * is a UI display-section grouping, not an importance tier) per product
 * type, so the scoring engine (Phase 3) never has to call SP-API itself.
 *
 * CA-only per Phase 0 (Vendor Central on this account can't reach the US
 * marketplace at all — confirmed live, not a config gap) — marketplace_id
 * is still a real column on cdq_product_type_schema, not hardcoded, so
 * extending past Vendor Central later is a new row shape, not a migration.
 */
class CdqSyncSchemasCommand extends Command
{
    protected $signature = 'cdq:sync-schemas';

    protected $description = 'CDQ Phase 1 — fetches and flattens Product Type Definitions for every product type in the active Vendor Central catalog';

    public function handle(SpApiClient $client): int
    {
        // Union of products.type (our local DB column, sometimes stale/
        // truncated — see the FLAVORED_DRINK_CONCENT/PROTEIN_SUPPLEMENT_POW
        // note below) and cdq_listing_snapshot.product_type (sourced live
        // from Catalog Items during cdq:snapshot, which is Amazon's own
        // authoritative value and can legitimately differ from our local
        // column). Confirmed live: 33 real ASINs carry a correct Catalog-
        // Items product type — PROTEIN_SUPPLEMENT_POWDER/
        // FLAVORED_DRINK_CONCENTRATE — that products.type alone never
        // surfaces, so relying on products.type alone silently left their
        // ATTR-dimension scoring with no schema to check against.
        $productTypes = Product::where('active', true)->whereNotNull('type')->distinct()->pluck('type')
            ->merge(
                CdqListingSnapshot::whereIn('id', function ($q) {
                    $q->selectRaw('MAX(id)')->from('cdq_listing_snapshot')->groupBy('asin');
                })->whereNotNull('product_type')->distinct()->pluck('product_type')
            )
            ->unique()
            ->values();

        if ($productTypes->isEmpty()) {
            $this->error('No active products with a product type found — nothing to sync.');

            return self::FAILURE;
        }

        $marketplaceId = ListingChannel::Vendor->marketplaceId();
        $synced = 0;
        $failed = [];

        foreach ($productTypes as $productType) {
            try {
                $schema = $client->getProductTypeSchema($productType, ListingChannel::Vendor);
                if ($schema === null) {
                    // Confirmed live at least once: a product_type string
                    // that doesn't exist in Amazon's real product type enum
                    // 404s here — not a capability gap, a bad value in
                    // products.type (varchar(60), so not a DB truncation;
                    // two real ones seen so far were suspiciously truncated
                    // at exactly 23 characters, pointing at the source
                    // import instead). Every affected product is
                    // permanently unscoreable by CDQ until this is fixed —
                    // surfaced with the real count, not just the type name.
                    $affected = Product::where('active', true)->where('type', $productType)->count();
                    $affectedAsins = CdqListingSnapshot::whereIn('id', function ($q) {
                        $q->selectRaw('MAX(id)')->from('cdq_listing_snapshot')->groupBy('asin');
                    })->where('product_type', $productType)->count();
                    $failed[] = "{$productType} (404 — not a real Amazon product type; {$affected} active product(s) with this products.type value, {$affectedAsins} ASIN(s) reporting it live via Catalog Items)";

                    continue;
                }

                $required = array_values($schema['required'] ?? []);
                $recommended = array_values(array_diff(array_keys($schema['properties'] ?? []), $required));

                CdqProductTypeSchema::updateOrCreate(
                    ['product_type' => $productType, 'marketplace_id' => $marketplaceId],
                    [
                        // getProductTypeSchema() returns only the downloaded schema
                        // document (following schema.link.resource), not the wrapper
                        // response — productTypeVersion lives on the wrapper, not
                        // here. Left null rather than adding a second live call for
                        // one optional field (nullable by the brief's own design;
                        // doesn't block this phase's actual deliverable).
                        'schema_version' => null,
                        'required_json' => $required,
                        'recommended_json' => $recommended,
                        'raw_schema' => $schema,
                        'fetched_at' => now(),
                    ]
                );

                $synced++;
                $this->components->twoColumnDetail($productType, count($required).' required, '.count($recommended).' recommended');
            } catch (\Throwable $e) {
                $failed[] = "{$productType}: {$e->getMessage()}";
            }
        }

        $this->newLine();
        $this->info("Synced {$synced}/{$productTypes->count()} product type schema(s).");
        if ($failed) {
            $this->warn('Failed: '.implode('; ', $failed));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
