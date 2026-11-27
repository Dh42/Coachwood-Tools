<?php

namespace App\Console\Commands;

use App\Models\CdqListingSnapshot;
use App\Models\Product;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\ListingChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * CDQ Phase 2 — reuses SpApiClient directly (the same client
 * ListingAuditService/ImageAuditService already use), not
 * ListingAuditService::auditOne() itself: that method computes aggregate
 * flags for the audit UI (has_serving_quantity, aplus_status, etc.), not
 * the raw content this snapshot needs to run CDQ's own scoring against
 * independently in Phase 3.
 *
 * Attributes come from Listings Items, not Catalog Items — confirmed by
 * SpApiClient::getListingsItem()'s own docblock ("Catalog Items GET is a
 * filtered/smaller view that silently omits nutritional_info and several
 * other editable attributes") and already how auditOne() itself sources
 * attributes. Catalog Items is used only for what Listings Items doesn't
 * have: images, variation relationships, classifications, dimensions,
 * salesRanks, vendorDetails.
 *
 * Fixed-interval pacing (no token bucket — matches the VC Replenishment
 * convention, accepted for this catalog's real size, ~103 active
 * products). Resumable/idempotent for free: content_hash dedupes, so a
 * re-run after a crash just re-fetches everything (cheap at this volume,
 * a few minutes) and only inserts rows for content that's genuinely new
 * or changed since the last successful capture.
 */
class CdqSnapshotCommand extends Command
{
    protected $signature = 'cdq:snapshot {--asin= : Snapshot a single ASIN instead of the full active catalog}';

    protected $description = 'CDQ Phase 2 — snapshots Catalog Items + A+ + Listings Items issues into cdq_listing_snapshot, deduped by content hash';

    public function handle(SpApiClient $client): int
    {
        $products = $this->resolveProducts();
        if ($products->isEmpty()) {
            $this->error('No matching active product(s) found.');

            return self::FAILURE;
        }

        $this->info('Building A+ document -> ASIN index (once per run, not per ASIN)...');
        $aplusIndex = $this->buildAplusIndex($client);
        $this->info('  '.count($aplusIndex).' ASIN(s) have at least one A+ document.');
        $this->newLine();

        $captured = 0;
        $unchanged = 0;
        $failed = [];

        foreach ($products as $i => $product) {
            try {
                $this->snapshotOne($client, $product, $aplusIndex) ? $captured++ : $unchanged++;
            } catch (\Throwable $e) {
                $failed[] = "{$product->asin}: {$e->getMessage()}";
            }

            if ($i < $products->count() - 1) {
                usleep(1_100_000);
            }
        }

        $this->info("Captured {$captured} new snapshot(s), {$unchanged} unchanged (content_hash matched, skipped).");
        if ($failed) {
            $this->warn('Failed: '.implode('; ', $failed));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function resolveProducts(): Collection
    {
        $query = Product::where('active', true)->whereNotNull('asin')->whereNotNull('sku');
        if ($asin = $this->option('asin')) {
            $query->where('asin', $asin);
        }

        return $query->get();
    }

    /**
     * One pass over every A+ document on the account — searchContentDocuments
     * has no per-ASIN filter, so building this once up front and reusing it
     * for every product below is what keeps this to ~1 extra call set per
     * run instead of per ASIN. Module detail is only fetched for APPROVED
     * (live) documents — DRAFT isn't published, doesn't affect a live
     * listing's score. getContentDocument() genuinely 400s on some real
     * content this account has (confirmed live: "Brand Story" documents —
     * "Unsupported content module type [...]", an Amazon-side gap, not
     * fixable here) — left as null ("we don't know"), not treated as zero
     * modules.
     *
     * @return array<string, array<int, array{name: ?string, status: ?string, module_count: ?int, has_comparison_table: ?bool, has_video: ?bool}>> keyed by ASIN
     */
    private function buildAplusIndex(SpApiClient $client): array
    {
        $documents = [];
        $pageToken = null;
        do {
            $result = $client->searchContentDocuments(ListingChannel::Vendor, $pageToken);
            if (($result['status'] ?? null) !== 200) {
                $this->warn('searchContentDocuments failed (HTTP '.($result['status'] ?? 'unknown').') — A+ data will be empty for this run.');

                return [];
            }
            $documents = [...$documents, ...($result['body']['contentMetadataRecords'] ?? [])];
            $pageToken = $result['body']['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        $index = [];
        foreach ($documents as $i => $doc) {
            $key = $doc['contentReferenceKey'];
            $status = $doc['contentMetadata']['status'] ?? null;

            $asins = [];
            $relPageToken = null;
            do {
                $relations = $client->listContentDocumentAsinRelations($key, ListingChannel::Vendor);
                if (($relations['status'] ?? null) !== 200) {
                    break;
                }
                $asins = [...$asins, ...array_column($relations['body']['asinMetadataSet'] ?? [], 'asin')];
                $relPageToken = $relations['body']['nextPageToken'] ?? null;
            } while ($relPageToken !== null);

            $detail = [
                'name' => $doc['contentMetadata']['name'] ?? null,
                'status' => $status,
                'module_count' => null,
                'has_comparison_table' => null,
                'has_video' => null,
            ];

            if ($status === 'APPROVED') {
                $docDetail = $client->getContentDocument($key, ListingChannel::Vendor);
                if (($docDetail['status'] ?? null) === 200) {
                    $modules = $docDetail['body']['contentRecord']['contentDocument']['contentModuleList'] ?? [];
                    $moduleTypes = array_column($modules, 'contentModuleType');
                    $detail['module_count'] = count($modules);
                    $detail['has_comparison_table'] = in_array('STANDARD_COMPARISON_TABLE', $moduleTypes, true);
                    // No fixed "VIDEO" module type is documented for A+ —
                    // matched by substring so a Premium-A+ video module
                    // (not seen live on this account yet) is still caught.
                    $detail['has_video'] = (bool) array_filter($moduleTypes, fn ($type) => str_contains($type, 'VIDEO'));
                }
            }

            foreach ($asins as $asin) {
                $index[$asin][] = $detail;
            }

            if ($i < count($documents) - 1) {
                usleep(300_000);
            }
        }

        return $index;
    }

    private function snapshotOne(SpApiClient $client, Product $product, array $aplusIndex): bool
    {
        $marketplaceId = ListingChannel::Vendor->marketplaceId();

        $listingsResult = $client->getListingsItem($product->sku, ListingChannel::Vendor);
        $attributes = [];
        $issues = null;
        $issuesCheckedAt = null;
        if (($listingsResult['status'] ?? null) === 200) {
            $attributes = $listingsResult['body']['attributes'] ?? [];
            $issues = $listingsResult['body']['issues'] ?? [];
            $issuesCheckedAt = now();
        }

        $catalogResult = $client->getCatalogItemWithIncludedData($product->asin, ListingChannel::Vendor, [
            'summaries', 'images', 'productTypes', 'relationships', 'classifications', 'dimensions', 'salesRanks', 'vendorDetails',
        ]);
        $catalogBody = ($catalogResult['status'] ?? null) === 200 ? ($catalogResult['body'] ?? []) : [];

        $aplus = $aplusIndex[$product->asin] ?? [];
        $productType = $catalogBody['productTypes'][0]['productType'] ?? $product->type;

        $normalized = [
            // Included in the hash deliberately: this can fall back to
            // products.type when Catalog Items omits productTypes (see
            // below), and that local column can be corrected independently
            // of the rest of this listing's content — without it in the
            // hash, a fix to products.type would never produce a new
            // snapshot row (and cdq:sync-schemas would keep failing against
            // the stale cached value) unless something else also changed.
            'product_type' => $productType,
            'attributes' => $attributes,
            'images' => $catalogBody['images'] ?? [],
            'relationships' => $catalogBody['relationships'] ?? [],
            'classifications' => $catalogBody['classifications'] ?? [],
            'dimensions' => $catalogBody['dimensions'] ?? [],
            'salesRanks' => $catalogBody['salesRanks'] ?? [],
            'vendorDetails' => $catalogBody['vendorDetails'] ?? [],
            'issues' => $issues,
            'aplus' => $aplus,
        ];
        $contentHash = $this->hashContent($normalized);

        $alreadyCaptured = CdqListingSnapshot::where('asin', $product->asin)
            ->where('marketplace_id', $marketplaceId)
            ->where('content_hash', $contentHash)
            ->exists();

        if ($alreadyCaptured) {
            return false;
        }

        CdqListingSnapshot::create([
            'asin' => $product->asin,
            'sku' => $product->sku,
            'vendor_code' => config('services.sp_api.vendor_code'),
            'marketplace_id' => $marketplaceId,
            'product_type' => $productType,
            'parent_asin' => $this->extractParentAsin($catalogBody),
            'content_hash' => $contentHash,
            'catalog_json' => $catalogBody,
            'attributes_json' => $attributes,
            // Only fetched once we already know this is a new/changed
            // snapshot — an unchanged re-run (the common case on a repeat
            // run) never pays for an image download it doesn't need.
            'main_image_hash' => $this->hashMainImage($catalogBody['images'][0]['images'] ?? []),
            'aplus_json' => $aplus,
            'issues_json' => $issues,
            'issues_checked_at' => $issuesCheckedAt,
            'captured_at' => now(),
        ]);

        return true;
    }

    /**
     * The MAIN variant's own byte hash, for VARIANT_IMAGE_SHARED (Phase 3)
     * to compare siblings without a live fetch at scoring time — scoring
     * must stay pure/replayable off the stored snapshot alone. Picks the
     * largest-resolution MAIN entry: Catalog Items returns each variant at
     * several resolutions as separate array entries, not necessarily
     * largest-first (confirmed against a real response — MAIN appeared as
     * 500x499, then a 75x75 thumbnail, then the real 1420x1417 full size,
     * in that order) — AttributeEngine::dedupeImagesByVariant() keeps
     * whichever happens to be first, which is fine for a plain variant
     * *count* but wrong here, where the actual resolution matters.
     */
    private function hashMainImage(array $images): ?string
    {
        $mainImages = array_filter($images, fn ($img) => ($img['variant'] ?? null) === 'MAIN');
        if (empty($mainImages)) {
            return null;
        }

        $largest = collect($mainImages)->sortByDesc(fn ($img) => ($img['height'] ?? 0) * ($img['width'] ?? 0))->first();
        $url = $largest['link'] ?? null;
        if ($url === null) {
            return null;
        }

        try {
            $response = Http::timeout(15)->get($url);

            return $response->successful() ? hash('sha256', $response->body()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractParentAsin(array $catalogBody): ?string
    {
        foreach ($catalogBody['relationships'][0]['relationships'] ?? [] as $relationship) {
            if (($relationship['type'] ?? null) === 'VARIATION' && ! empty($relationship['parentAsins'])) {
                return $relationship['parentAsins'][0];
            }
        }

        return null;
    }

    /** Recursively key-sorted before hashing so response key reordering between calls can't produce a spurious "content changed." */
    private function hashContent(array $normalized): string
    {
        $this->recursiveKsort($normalized);

        return hash('sha256', json_encode($normalized));
    }

    private function recursiveKsort(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
    }
}
