<?php

namespace App\Http\Controllers\Tools\Listings;

use App\Http\Controllers\Controller;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\AttributeEngine;
use App\Services\AmazonListings\ListingAuditService;
use App\Services\AmazonListings\ListingChannel;
use App\Services\AmazonListings\NutritionFormBuilder;
use App\Services\Cdq\CdqReportService;
use App\Support\ExternalWrites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

class VendorListingController extends Controller
{
    protected ListingChannel $channel = ListingChannel::Vendor;

    public function __construct(
        protected SpApiClient $client,
        protected ListingAuditService $auditService,
    ) {}

    public function index(Request $request): View|Response
    {
        $products = Product::query()->active()->with('latestListingAudit')->orderBy('title')->get();

        $selectedAsin = (string) $request->query('asin', '');
        $selectedProduct = $selectedAsin !== '' ? $products->firstWhere('asin', $selectedAsin) : null;

        $editorData = null;
        $catalogError = null;
        if ($selectedProduct) {
            try {
                $editorData = $this->loadEditorData($selectedProduct);
            } catch (Throwable $e) {
                $catalogError = $e->getMessage();
            }
        }

        if ($request->boolean('ajax')) {
            if ($catalogError) {
                return response('<p class="text-danger">'.e($catalogError).'</p>');
            }

            return response()->view('tools.listings._editor', ($editorData ?? []) + ['submitRoute' => route('tools.vc-listings.submit')]);
        }

        return view('tools.listings.vendor.index', [
            'products' => $products,
            'channel' => $this->channel,
            'selectedAsin' => $selectedAsin,
            'editorData' => $editorData,
            'catalogError' => $catalogError,
            'auditConfig' => $this->auditService->configuredSlots($this->channel),
            'auditAliases' => $this->auditService->configuredAliases($this->channel),
            'auditRawSlots' => $this->auditService->configuredRawSlots($this->channel),
            'auditRawAliases' => $this->auditService->configuredRawAliases($this->channel),
            'availableAttributes' => $this->auditService->availableAttributes($this->channel),
            'lastFullAudit' => ($max = $this->channel->listingAuditModel()::max('checked_at')) ? Carbon::parse($max) : null,
            // CDQ only ever scores Vendor Central ASINs — $this->channel is
            // always Vendor on this controller (SellerListingController is
            // the separate class for the Seller Central page), so no gate
            // needed here; the gate lives in _product-row.blade.php, which
            // this view's own <td> shares with the seller page.
            'cdqByAsin' => CdqReportService::currentScoresByAsin(),
            'exportColumns' => array_values($this->exportColumns()),
        ]);
    }

    protected function loadEditorData(Product $product): array
    {
        $listingsResult = $this->client->getListingsItem($product->sku, $this->channel);
        if (($listingsResult['status'] ?? null) !== 200) {
            throw new \RuntimeException('Could not load this listing (HTTP '.($listingsResult['status'] ?? 'unknown').').');
        }

        $catalogResult = $this->client->getCatalogItem($product->asin, $this->channel);

        // A second, small Catalog Items call for classifications specifically
        // — getCatalogItem()'s own fixed includedData set doesn't ask for it
        // (see that method's docblock: it's a deliberately narrow, proven
        // set). Caught on its own, never blocking the editor itself — a
        // browse node's raw number is still shown either way, this only
        // adds the friendly name alongside it when resolvable.
        $browseNodeNames = [];
        try {
            $classResult = $this->client->getCatalogItemWithIncludedData($product->asin, $this->channel, ['classifications']);
            if (($classResult['status'] ?? null) === 200) {
                $browseNodeNames = $this->extractBrowseNodeNames($classResult['body']['classifications'] ?? []);
            }
        } catch (Throwable) {
            // Degrade gracefully — see comment above.
        }

        $attributes = $listingsResult['body']['attributes'] ?? [];
        $productType = $listingsResult['body']['productType']
            ?? ($catalogResult['body']['productTypes'][0]['productType'] ?? null);

        // Fetched (and cached ~24h — see SpApiClient::getProductTypeSchema())
        // regardless of which attributes this listing actually has, so the
        // generic attribute loop can offer a real Amazon-constrained
        // dropdown for any enum-typed field, not just nutrition's. Caught
        // on its own — unlike the listings/catalog calls above, a schema
        // fetch failure shouldn't block viewing or editing the listing
        // itself, just degrade enum fields back to plain text inputs.
        $schemaProps = [];
        if ($productType) {
            try {
                $schema = $this->client->getProductTypeSchema($productType, $this->channel);
                $schemaProps = $schema['properties'] ?? [];
            } catch (Throwable) {
                // Degrade gracefully — see comment above.
            }
        }
        $nutritionSchemaProps = $schemaProps['nutritional_info']['items']['properties'] ?? [];

        $audit = $product->latestListingAudit;
        $mismatchedAttrs = $audit && $audit->contribution_mismatch_attrs
            ? explode(',', $audit->contribution_mismatch_attrs)
            : [];

        return [
            'channel' => $this->channel,
            'product' => $product,
            'catalog' => $catalogResult['body'] ?? [],
            'attributes' => $attributes,
            'productType' => $productType,
            'schemaProps' => $schemaProps,
            'nutritionSchemaProps' => $nutritionSchemaProps,
            'mismatchedAttrs' => $mismatchedAttrs,
            'browseNodeNames' => $browseNodeNames,
            'edits' => ListingEdit::where('asin', $product->asin)->with('user')->latest('created_at')->limit(30)->get(),
        ];
    }

    /**
     * Flattens Catalog Items' `classifications` include into a flat
     * classificationId => displayName map, walking the full parent chain
     * (not just the ASIN's own immediate node) — confirmed live the parent
     * chain's own ids are real, resolvable browse node ids too, so this
     * gets more mileage out of one Catalog Items call than just mapping
     * the leaf node.
     *
     * @return array<string, string>
     */
    private function extractBrowseNodeNames(array $classifications): array
    {
        $names = [];
        foreach ($classifications as $marketplaceBlock) {
            foreach ($marketplaceBlock['classifications'] ?? [] as $node) {
                $this->collectClassificationNames($node, $names);
            }
        }

        return $names;
    }

    /** @param  array<string, string>  $names */
    private function collectClassificationNames(?array $node, array &$names): void
    {
        if ($node === null) {
            return;
        }

        if (isset($node['classificationId'], $node['displayName'])) {
            $names[(string) $node['classificationId']] = (string) $node['displayName'];
        }

        if (isset($node['parent']) && is_array($node['parent'])) {
            $this->collectClassificationNames($node['parent'], $names);
        }
    }

    public function submit(Request $request): RedirectResponse
    {
        $asin = (string) $request->input('asin');
        $sku = (string) $request->input('sku');
        $productType = (string) $request->input('product_type');
        $product = Product::where('asin', $asin)->first();

        $changes = $this->detectChanges($request, $this->channel->marketplaceId());

        if (empty($changes)) {
            return redirect()->route('tools.vc-listings.index', ['asin' => $asin])->with('status', 'Nothing changed — no submission made.');
        }

        $patchOps = array_column($changes, 'patch');

        try {
            $preview = $this->client->patchListing($sku, $productType, $patchOps, true, $this->channel);
        } catch (Throwable $e) {
            return redirect()->route('tools.vc-listings.index', ['asin' => $asin])->with('error', 'Validation call failed: '.$e->getMessage());
        }

        $previewIssues = array_filter($preview['body']['issues'] ?? [], fn ($i) => ($i['severity'] ?? '') === 'ERROR');
        if (($preview['status'] ?? null) !== 200 || ($preview['body']['status'] ?? null) !== 'VALID' || ! empty($previewIssues)) {
            $messages = array_map(fn ($i) => $i['message'] ?? 'unknown issue', $preview['body']['issues'] ?? []);

            return redirect()->route('tools.vc-listings.index', ['asin' => $asin])
                ->with('error', 'Amazon rejected this change during validation: '.implode('; ', $messages ?: ['HTTP '.$preview['status']]));
        }

        // Plan section 2, rule 3: until cutover only the preview runs. Nothing
        // is submitted, so nothing is logged either.
        if (! ExternalWrites::allowed('listings')) {
            return redirect()->route('tools.vc-listings.index', ['asin' => $asin])->with('warning', __(
                "Amazon's validation preview accepted :n change(s) (:attributes). Submissions are off in this app until cutover, so nothing was sent to Amazon and nothing was logged. Make this change in the old app.",
                ['n' => count($changes), 'attributes' => implode(', ', array_column($changes, 'attr'))],
            ));
        }

        try {
            $real = $this->client->patchListing($sku, $productType, $patchOps, false, $this->channel);
        } catch (Throwable $e) {
            return redirect()->route('tools.vc-listings.index', ['asin' => $asin])->with('error', 'Submission call failed: '.$e->getMessage());
        }

        $status = $real['body']['status'] ?? 'REJECTED';
        foreach ($changes as $c) {
            ListingEdit::create([
                'product_id' => $product?->id,
                'user_id' => $request->user()->id,
                'asin' => $asin,
                'sku' => $sku,
                'attribute' => $c['attr'],
                'old_value' => json_encode($c['old']),
                'new_value' => json_encode($c['new']),
                'submission_id' => $real['body']['submissionId'] ?? null,
                'status' => $status,
                'issues_json' => json_encode($real['body']['issues'] ?? []),
            ]);
        }

        if (($real['status'] ?? null) !== 200 || $status !== 'ACCEPTED') {
            return redirect()->route('tools.vc-listings.index', ['asin' => $asin])->with('error', "Submission was not accepted (status: {$status}).");
        }

        return redirect()->route('tools.vc-listings.index', ['asin' => $asin])->with(
            'status',
            count($changes).' field(s) submitted — accepted by Amazon (submission '.($real['body']['submissionId'] ?? '?').'). Changes are applied asynchronously and may take a few minutes to appear live.'
        );
    }

    /**
     * Diffs posted attributes/nutrition against their `orig[...]` hidden-
     * field snapshots and returns only what actually changed, each with a
     * ready-to-send JSON-Patch op — ported verbatim from the legacy
     * submit_edit handler.
     *
     * @return array<int, array{attr: string, old: mixed, new: mixed, patch: array}>
     */
    protected function detectChanges(Request $request, string $marketplaceId): array
    {
        $changes = [];

        foreach ($request->input('attr', []) as $attrKey => $itemsPosted) {
            $origItems = json_decode((string) $request->input("orig.{$attrKey}", '[]'), true) ?: [];
            $template = $origItems[0] ?? ['marketplace_id' => $marketplaceId];

            $newItems = [];
            foreach ($itemsPosted as $idx => $leafValues) {
                $item = $origItems[$idx] ?? $template;
                $allEmpty = true;
                foreach ($leafValues as $pathStr => $rawVal) {
                    $path = explode('.', $pathStr);
                    $val = trim(str_replace("\r\n", "\n", (string) $rawVal));
                    if ($val !== '') {
                        $allEmpty = false;
                    }

                    $origLeaf = AttributeEngine::getPath($item, $path);
                    if (is_bool($origLeaf)) {
                        $val = $rawVal === '1';
                    } elseif (is_int($origLeaf)) {
                        $val = $val === '' ? null : (int) $val;
                    } elseif (is_float($origLeaf)) {
                        $val = $val === '' ? null : (float) $val;
                    } elseif ($val === '') {
                        $val = null;
                    }
                    AttributeEngine::setPath($item, $path, $val);
                }

                if (array_key_exists('marketplace_id', $item)) {
                    $item['marketplace_id'] = $marketplaceId;
                }
                if ($allEmpty) {
                    continue;
                }
                $newItems[] = $item;
            }

            if (! AttributeEngine::sameValue($newItems, array_values($origItems))) {
                $changes[] = [
                    'attr' => $attrKey,
                    'old' => $origItems,
                    'new' => $newItems,
                    'patch' => ['op' => 'replace', 'path' => "/attributes/{$attrKey}", 'value' => $newItems],
                ];
            }
        }

        if ($request->has('nutrition')) {
            $nutritionSchemaProps = json_decode((string) $request->input('nutrition_schema_props', '[]'), true) ?: [];
            $origNutrition = json_decode((string) $request->input('orig_nutritional_info', '[]'), true) ?: [];
            $newItem = NutritionFormBuilder::build($request->input('nutrition', []), array_flip($nutritionSchemaProps), $marketplaceId);
            $newItems = count($newItem) > 1 ? [$newItem] : [];

            if (! AttributeEngine::sameValue($newItems, $origNutrition)) {
                $changes[] = [
                    'attr' => 'nutritional_info',
                    'old' => $origNutrition,
                    'new' => $newItems,
                    'patch' => ['op' => 'replace', 'path' => '/attributes/nutritional_info', 'value' => $newItems],
                ];
            }
        }

        return $changes;
    }

    public function auditRun(Request $request): RedirectResponse
    {
        set_time_limit(600);
        $result = $this->auditService->auditAll($this->channel);

        $message = "Audit complete — checked {$result['checked']} product(s)";
        $message .= $result['failed'] > 0 ? ", {$result['failed']} failed to fetch." : '.';

        return redirect()->route('tools.vc-listings.index')->with('status', $message);
    }

    public function auditBatch(Request $request): JsonResponse
    {
        set_time_limit(60);
        $offset = $request->integer('offset', 0);
        $batch = $request->integer('batch', 5);

        $result = $this->auditService->auditBatch($this->channel, $offset, $batch);
        $auditConfig = $this->auditService->configuredSlots($this->channel);
        $cdqByAsin = CdqReportService::currentScoresByAsin();

        $rows = $result['products']->map(fn ($product) => [
            'id' => $product->id,
            'html' => view('tools.listings._product-row', [
                'product' => $product,
                'channel' => $this->channel,
                'auditConfig' => $auditConfig,
                'showListingIssues' => true,
                'cdqByAsin' => $cdqByAsin,
            ])->render(),
        ])->values();
        unset($result['products']);

        if ($result['done']) {
            $max = $this->channel->listingAuditModel()::max('checked_at');
            $result['lastFullAudit'] = $max ? Carbon::parse($max)->format('Y-m-d H:i:s T') : null;
        }

        return response()->json($result + ['rows' => $rows]);
    }

    /** Admins only (found issue 9): the columns are shared by everyone who uses the page. */
    public function auditConfig(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $slots = [];
        $aliases = [];
        for ($i = 1; $i <= 7; $i++) {
            $slots[] = $request->input("attr_slot_{$i}", '');
            $aliases[] = $request->input("alias_slot_{$i}", '');
        }

        $this->auditService->updateConfig($this->channel, $slots, $aliases, $request->input('config_reason'), $request->user()->id);

        return redirect()->route('tools.vc-listings.index')->with('status', 'Audit column configuration updated.');
    }

    /**
     * Every column the "Export All" CSV will have, in order — a fixed
     * identity set plus every attribute key ListingAuditAttributeCatalog
     * has ever actually seen on a live audit run (the same source
     * ListingAuditService::availableAttributes() already draws the column-
     * picker's own options from), so this is free to compute on every page
     * load with no live SP-API call. `recommended_browse_nodes` gets its
     * raw node id plus, where resolvable, the friendly name appended — see
     * flattenAttributeForExport(). `brand_code`/`manufacturer_code` are
     * synthetic keys too, like the identity set — they come from Catalog
     * Items' `vendorDetails`, not a Listings Items attribute, so they'd
     * never appear via the attribute-catalog loop below on their own.
     *
     * @return array<string, string> attribute key (or a synthetic identity
     *                               key) => CSV column header
     */
    protected function exportColumns(): array
    {
        $columns = [
            'asin' => 'ASIN', 'sku' => 'SKU', 'title' => 'Title', 'product_type' => 'Product Type',
            'brand_code' => 'Brand Code', 'manufacturer_code' => 'Manufacturer Code',
        ];

        foreach ($this->channel->attributeCatalogModel()::orderBy('attribute_key')->pluck('attribute_key') as $key) {
            $columns[$key] = AttributeEngine::humanizeKey($key);
        }

        $columns['fetch_error'] = 'Fetch Error';

        return $columns;
    }

    /**
     * One batch of the "Export All" CSV — same offset/batch/pacing shape as
     * auditBatch(), but returns raw row data (JSON) instead of rendered
     * HTML, since the client is assembling one CSV file rather than
     * patching table rows in place. Each product costs 2 SP-API calls
     * (Listings Items GET + a Catalog Items GET for classifications and,
     * when this channel supports it, vendorDetails) — still just those 2,
     * same as before brand_code/manufacturer_code were added: that data
     * rides along on the existing classifications call rather than costing
     * a 3rd request.
     */
    public function exportBatch(Request $request): JsonResponse
    {
        set_time_limit(60);
        $offset = $request->integer('offset', 0);
        $batch = min(10, max(1, $request->integer('batch', 5)));

        $columnKeys = array_keys($this->exportColumns());

        $total = Product::query()->active()->count();
        $products = Product::query()->active()->orderBy('id')->skip($offset)->take($batch)->get();

        $rows = [];
        foreach ($products as $i => $product) {
            $rows[] = $this->exportRow($product, $columnKeys);
            if ($i < $products->count() - 1) {
                usleep(550_000);
            }
        }

        $nextOffset = $offset + $products->count();

        return response()->json([
            'processed' => $products->count(),
            'nextOffset' => $nextOffset,
            'total' => $total,
            'done' => $nextOffset >= $total,
            'rows' => $rows,
        ]);
    }

    /** @return array<int, string> one row's cells, in $columnKeys order */
    private function exportRow(Product $product, array $columnKeys): array
    {
        $error = null;
        $attributes = [];
        $productType = $product->type;

        try {
            $listingsResult = $this->client->getListingsItem($product->sku, $this->channel);
            if (($listingsResult['status'] ?? null) !== 200) {
                throw new \RuntimeException('HTTP '.($listingsResult['status'] ?? 'unknown'));
            }
            $attributes = $listingsResult['body']['attributes'] ?? [];
            $productType = $listingsResult['body']['productType'] ?? $productType;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        // Best-effort, independent of the try/catch above — a browse-node
        // name or brand/manufacturer code lookup failure shouldn't blank out
        // a row that otherwise loaded fine.
        $includedData = ['classifications'];
        if ($this->channel->includeVendorDetails()) {
            $includedData[] = 'vendorDetails';
        }

        $browseNodeNames = [];
        $brandCode = null;
        $manufacturerCode = null;
        try {
            $classResult = $this->client->getCatalogItemWithIncludedData($product->asin, $this->channel, $includedData);
            if (($classResult['status'] ?? null) === 200) {
                $browseNodeNames = $this->extractBrowseNodeNames($classResult['body']['classifications'] ?? []);
                // Same vendorDetails[0] shape ListingAuditService::auditOne()
                // already reads for the same two fields.
                $vendorDetails = $classResult['body']['vendorDetails'][0] ?? [];
                $brandCode = $vendorDetails['brandCode'] ?? null;
                $manufacturerCode = $vendorDetails['manufacturerCode'] ?? null;
            }
        } catch (Throwable) {
            // Row still exports with just the raw node number and blank codes.
        }

        $row = [];
        foreach ($columnKeys as $key) {
            $row[] = match ($key) {
                'asin' => $product->asin,
                'sku' => $product->sku,
                'title' => $product->title,
                'product_type' => (string) $productType,
                'brand_code' => (string) ($brandCode ?? ''),
                'manufacturer_code' => (string) ($manufacturerCode ?? ''),
                'fetch_error' => $error ?? '',
                default => $this->flattenAttributeForExport($attributes[$key] ?? [], $key, $browseNodeNames),
            };
        }

        return $row;
    }

    /**
     * One attribute's item array reduced to a single spreadsheet-friendly
     * cell. The common case — a single `{value: ..., marketplace_id: ...}`
     * item, true for most attributes — becomes just the plain value; a
     * multi-leaf item (nutrition, dimensions, etc.) becomes a compact
     * `key=value, key2=value2` list instead of a raw JSON blob. Multiple
     * items join with '; '. `language_tag` leaves are dropped before this
     * decision — same convention as the editor's humanizeLeafPath(), which
     * treats language_tag as context rather than its own field — since
     * almost every text attribute item carries one alongside `value`, and
     * without dropping it first the "common case" above never actually
     * triggers.
     */
    private function flattenAttributeForExport(array $items, string $attrKey, array $browseNodeNames): string
    {
        if (empty($items)) {
            return '';
        }

        $parts = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $parts[] = (string) $item;

                continue;
            }

            $leaves = array_values(array_filter(
                AttributeEngine::leafPaths($item),
                fn ($leaf) => end($leaf[0]) !== 'language_tag'
            ));
            if (count($leaves) === 1 && $leaves[0][0] === ['value']) {
                $val = (string) $leaves[0][1];
                if ($attrKey === 'recommended_browse_nodes' && isset($browseNodeNames[$val])) {
                    $val .= ' — '.$browseNodeNames[$val];
                }
                $parts[] = $val;
            } else {
                $pairs = array_map(fn ($leaf) => implode('.', $leaf[0]).'='.$leaf[1], $leaves);
                $parts[] = implode(', ', $pairs);
            }
        }

        return implode('; ', $parts);
    }
}
