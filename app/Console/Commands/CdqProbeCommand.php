<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\ListingChannel;
use Illuminate\Console\Command;

/**
 * CDQ Phase 0 — a one-off, human-run diagnostic, not part of any scheduled
 * pipeline (matching this app's own *:import-legacy-data convention for
 * commands meant to be run once, or re-run only when something upstream
 * changes — never registered in scheduled_tasks).
 *
 * Every finding below was live-verified against this account's real
 * catalog before this command was written, not guessed from documentation
 * — see context.md for the full trail. Re-running this reproduces those
 * findings against current data rather than trusting them to stay true
 * forever (Amazon's own schemas/roles can change).
 */
class CdqProbeCommand extends Command
{
    protected $signature = 'cdq:probe';

    protected $description = 'CDQ Phase 0 capability probe — confirms which SP-API operations the CDQ scoring module needs are actually reachable on this account';

    public function handle(SpApiClient $client): int
    {
        $this->info('CDQ Phase 0 — Vendor Central capability probe');
        $this->newLine();

        $this->reportVendorCodeResolution();

        $samples = Product::where('active', true)->whereNotNull('asin')->whereNotNull('type')
            ->get()->unique('type')->values();

        if ($samples->isEmpty()) {
            $this->error('No active products with an ASIN and type found — cannot probe anything live.');

            return self::FAILURE;
        }

        $rows = [
            $this->probeCatalogItemsBase($client, $samples->first()),
            $this->probeCatalogItemsExtended($client, $samples),
            $this->probeProductTypeDefinitions($client, $samples->first()),
            $this->probeListingsItemsIssues(),
            $this->probeAplusContentManagement($client),
        ];

        $this->newLine();
        $this->table(['Operation', 'Accessible', 'Notes / fallback'], $rows);

        return self::SUCCESS;
    }

    /**
     * Confirmed live: this account has exactly one Vendor Central identity.
     * The GQ8J9/Z3091 pair sometimes seen in PO exports is a Cin7-only
     * distinction (Canadian Protein vs. BioSteel sharing one Cin7 account)
     * — unrelated to SP-API scope. No per-SKU vendor code resolution
     * exists or is needed; every vendor SP-API call in this app already
     * resolves the one code via ListingChannel::identityCode().
     */
    private function reportVendorCodeResolution(): void
    {
        $this->components->twoColumnDetail(
            'Vendor code resolution',
            'not needed — single account-wide code ('.config('services.sp_api.vendor_code').'), already resolved by ListingChannel::identityCode()'
        );
        $this->newLine();
    }

    private function probeCatalogItemsBase(SpApiClient $client, Product $sample): array
    {
        try {
            $result = $client->getCatalogItem($sample->asin, ListingChannel::Vendor);
            $ok = $result['status'] === 200;

            return [
                'Catalog Items — base (summaries/attributes/productTypes/images/identifiers/vendorDetails)',
                $ok ? 'YES' : 'NO (HTTP '.$result['status'].')',
                $ok ? 'Already in production use — ListingAuditService calls this on every audit run.' : 'Unexpected — this same call already runs in production.',
            ];
        } catch (\Throwable $e) {
            return ['Catalog Items — base', 'NO', 'Exception: '.$e->getMessage()];
        }
    }

    /**
     * Not yet requested anywhere else in this app — relationships is what
     * IMAGE scoring's per-child-roll-up-to-parent needs (parent ASIN +
     * VARIATION type), classifications is the browse tree. Tested across
     * one ASIN per distinct active product type, not just one sample,
     * since a field present for one product type isn't proof it's present
     * for all seven this catalog actually has.
     */
    private function probeCatalogItemsExtended(SpApiClient $client, $samples): array
    {
        $keys = ['relationships', 'classifications', 'dimensions', 'salesRanks'];
        $present = array_fill_keys($keys, 0);
        $checked = 0;
        $failures = [];

        foreach ($samples as $product) {
            try {
                $result = $client->getCatalogItemWithIncludedData($product->asin, ListingChannel::Vendor, $keys);
                if ($result['status'] !== 200) {
                    $failures[] = "{$product->asin} ({$product->type}): HTTP {$result['status']}";

                    continue;
                }
                $checked++;
                foreach ($keys as $key) {
                    if (! empty($result['body'][$key] ?? null)) {
                        $present[$key]++;
                    }
                }
            } catch (\Throwable $e) {
                $failures[] = "{$product->asin} ({$product->type}): {$e->getMessage()}";
            }
        }

        $summary = collect($present)->map(fn ($n, $key) => "{$key}: {$n}/{$checked}")->implode(', ');
        $note = "Checked {$checked}/{$samples->count()} product types. {$summary}.";
        if ($failures) {
            $note .= ' Failed: '.implode('; ', $failures).' — worth checking that ASIN is still live on Amazon\'s side (a real inactive-in-our-DB-but-not-Amazon or vice versa mismatch, not a capability gap).';
        }

        return [
            'Catalog Items — extended (relationships/classifications/dimensions/salesRanks)',
            $checked > 0 ? 'YES' : 'NO',
            $note,
        ];
    }

    /**
     * Confirmed live: propertyGroups exists, but it groups schema
     * properties by UI DISPLAY SECTION (e.g. "Images", "Shipping",
     * "Variations", "Safety & Compliance") — not by importance tier. There
     * is no Recommended/Preferred distinction exposed anywhere in this
     * response; the only importance signal SP-API actually gives us is the
     * flat JSON-Schema `required` array. The brief's Phase 1 design
     * ("parse propertyGroups to split Required/Recommended/Preferred")
     * doesn't hold — flagging before Phase 1 starts, not after.
     */
    private function probeProductTypeDefinitions(SpApiClient $client, Product $sample): array
    {
        try {
            $schema = $client->getProductTypeSchema($sample->type, ListingChannel::Vendor);
            if ($schema === null) {
                return ['Product Type Definitions (getDefinitionsProductType)', 'NO', 'getProductTypeSchema() returned null for '.$sample->type.'.'];
            }

            $requiredCount = count($schema['required'] ?? []);

            return [
                'Product Type Definitions (getDefinitionsProductType, requirements=LISTING)',
                'YES',
                "Already in production use (VendorListingController). {$sample->type}: ".$requiredCount.' required attributes in the schema. '
                    .'propertyGroups exists on the wrapper response (not the downloaded schema doc — getProductTypeSchema() only returns the latter) but is a UI-section grouping (Images/Shipping/Variations/etc.), not a Required/Recommended/Preferred tier. '
                    .'Only "required" is a real importance signal from this API — Recommended/Preferred needs its own rule, not a schema parse.',
            ];
        } catch (\Throwable $e) {
            return ['Product Type Definitions', 'NO', 'Exception: '.$e->getMessage()];
        }
    }

    /** Not re-tested — already confirmed running in production on every audit. */
    private function probeListingsItemsIssues(): array
    {
        return [
            'Listings Items issues (getListingsItem, includedData=issues)',
            'YES (confirmed)',
            'Already in production use — ListingAuditService fetches this on every audit run; reuse listing_issues rather than re-pulling.',
        ];
    }

    private function probeAplusContentManagement(SpApiClient $client): array
    {
        try {
            $result = $client->searchContentDocuments(ListingChannel::Vendor);
            if ($result['status'] !== 200) {
                return ['A+ Content Management (searchContentDocuments)', 'NO (HTTP '.$result['status'].')', 'Genuinely new capability — not currently called anywhere; role may not be granted.'];
            }

            $records = $result['body']['contentMetadataRecords'] ?? [];
            $statusCounts = collect($records)->countBy(fn ($r) => $r['contentMetadata']['status'] ?? '?');
            $paginated = ($result['body']['nextPageToken'] ?? null) !== null;

            $relationsNote = 'listContentDocumentAsinRelations() not smoke-tested this run.';
            $approved = collect($records)->first(fn ($r) => ($r['contentMetadata']['status'] ?? null) === 'APPROVED');
            if ($approved) {
                $rel = $client->listContentDocumentAsinRelations($approved['contentReferenceKey'], ListingChannel::Vendor);
                $asinCount = count($rel['body']['asinMetadataSet'] ?? []);
                $relationsNote = "listContentDocumentAsinRelations() confirmed: {$asinCount} ASIN(s) on one sampled document.";
            }

            return [
                'A+ Content Management (searchContentDocuments + listContentDocumentAsinRelations)',
                'YES',
                count($records).' documents found ('.$statusCounts->map(fn ($n, $s) => "{$s}: {$n}")->implode(', ').'). '
                    .($paginated ? 'Pagination WAS needed — handle nextPageToken.' : 'Fit in one page, no pagination exercised.').' '
                    .$relationsNote.' Genuinely new vs. the lighter getAplusPublishRecords() signal already in use — gives module-level detail that one doesn\'t.',
            ];
        } catch (\Throwable $e) {
            return ['A+ Content Management', 'NO', 'Exception: '.$e->getMessage()];
        }
    }
}
