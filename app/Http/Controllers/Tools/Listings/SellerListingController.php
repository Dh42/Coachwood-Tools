<?php

namespace App\Http\Controllers\Tools\Listings;

use App\Http\Controllers\Controller;
use App\Models\ScListingEdit;
use App\Models\ScProduct;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\AttributeEngine;
use App\Services\AmazonListings\ListingAuditService;
use App\Services\AmazonListings\ListingChannel;
use App\Services\AmazonListings\NutritionFormBuilder;
use App\Support\ExternalWrites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Same shape as VendorListingController — routed by SKU, not ASIN, unlike
 * the legacy tool. One seller ASIN can legitimately have more than one SKU
 * (confirmed live), and the legacy picker silently resolved to whichever
 * SKU loaded first with no way to pick the right one — a real risk for a
 * tool that writes live listing changes. Since sc_products is already
 * naturally one row per SKU, routing by SKU here just means every listing
 * is its own distinct, independently-editable row.
 */
class SellerListingController extends Controller
{
    protected ListingChannel $channel = ListingChannel::Seller;

    public function __construct(
        protected SpApiClient $client,
        protected ListingAuditService $auditService,
    ) {}

    public function index(Request $request): View|Response
    {
        $products = ScProduct::query()->active()->with('latestListingAudit')->orderBy('title')->get();

        $selectedSku = (string) $request->query('sku', '');
        $selectedProduct = $selectedSku !== '' ? $products->firstWhere('sku', $selectedSku) : null;

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

            return response()->view('tools.listings._editor', ($editorData ?? []) + ['submitRoute' => route('tools.sc-listings.submit')]);
        }

        return view('tools.listings.seller.index', [
            'products' => $products,
            'channel' => $this->channel,
            'selectedSku' => $selectedSku,
            'editorData' => $editorData,
            'catalogError' => $catalogError,
            'auditConfig' => $this->auditService->configuredSlots($this->channel),
            'auditAliases' => $this->auditService->configuredAliases($this->channel),
            'auditRawSlots' => $this->auditService->configuredRawSlots($this->channel),
            'auditRawAliases' => $this->auditService->configuredRawAliases($this->channel),
            'availableAttributes' => $this->auditService->availableAttributes($this->channel),
            'lastFullAudit' => ($max = $this->channel->listingAuditModel()::max('checked_at')) ? Carbon::parse($max) : null,
        ]);
    }

    protected function loadEditorData(ScProduct $product): array
    {
        $listingsResult = $this->client->getListingsItem($product->sku, $this->channel);
        if (($listingsResult['status'] ?? null) !== 200) {
            throw new RuntimeException('Could not load this listing (HTTP '.($listingsResult['status'] ?? 'unknown').').');
        }

        $catalogResult = $this->client->getCatalogItem($product->asin, $this->channel);

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
            'edits' => ScListingEdit::where('sku', $product->sku)->with('user')->latest('created_at')->limit(30)->get(),
        ];
    }

    public function submit(Request $request): RedirectResponse
    {
        $asin = (string) $request->input('asin');
        $sku = (string) $request->input('sku');
        $productType = (string) $request->input('product_type');
        $product = ScProduct::where('sku', $sku)->first();

        $changes = $this->detectChanges($request, $this->channel->marketplaceId());

        if (empty($changes)) {
            return redirect()->route('tools.sc-listings.index', ['sku' => $sku])->with('status', 'Nothing changed — no submission made.');
        }

        $patchOps = array_column($changes, 'patch');

        try {
            $preview = $this->client->patchListing($sku, $productType, $patchOps, true, $this->channel);
        } catch (Throwable $e) {
            return redirect()->route('tools.sc-listings.index', ['sku' => $sku])->with('error', 'Validation call failed: '.$e->getMessage());
        }

        $previewIssues = array_filter($preview['body']['issues'] ?? [], fn ($i) => ($i['severity'] ?? '') === 'ERROR');
        if (($preview['status'] ?? null) !== 200 || ($preview['body']['status'] ?? null) !== 'VALID' || ! empty($previewIssues)) {
            $messages = array_map(fn ($i) => $i['message'] ?? 'unknown issue', $preview['body']['issues'] ?? []);

            return redirect()->route('tools.sc-listings.index', ['sku' => $sku])
                ->with('error', 'Amazon rejected this change during validation: '.implode('; ', $messages ?: ['HTTP '.$preview['status']]));
        }

        // Plan section 2, rule 3: until cutover only the preview runs. Nothing
        // is submitted, so nothing is logged either.
        if (! ExternalWrites::allowed()) {
            return redirect()->route('tools.sc-listings.index', ['sku' => $sku])->with('warning', __(
                "Amazon's validation preview accepted :n change(s) (:attributes). Submissions are off in this app until cutover, so nothing was sent to Amazon and nothing was logged. Make this change in the old app.",
                ['n' => count($changes), 'attributes' => implode(', ', array_column($changes, 'attr'))],
            ));
        }

        try {
            $real = $this->client->patchListing($sku, $productType, $patchOps, false, $this->channel);
        } catch (Throwable $e) {
            return redirect()->route('tools.sc-listings.index', ['sku' => $sku])->with('error', 'Submission call failed: '.$e->getMessage());
        }

        $status = $real['body']['status'] ?? 'REJECTED';
        foreach ($changes as $c) {
            ScListingEdit::create([
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
            return redirect()->route('tools.sc-listings.index', ['sku' => $sku])->with('error', "Submission was not accepted (status: {$status}).");
        }

        return redirect()->route('tools.sc-listings.index', ['sku' => $sku])->with(
            'status',
            count($changes).' field(s) submitted — accepted by Amazon (submission '.($real['body']['submissionId'] ?? '?').'). Changes are applied asynchronously and may take a few minutes to appear live.'
        );
    }

    /** Identical logic to VendorListingController::detectChanges() — see that class for the full port notes. */
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

        return redirect()->route('tools.sc-listings.index')->with('status', $message);
    }

    public function auditBatch(Request $request): JsonResponse
    {
        set_time_limit(60);
        $offset = $request->integer('offset', 0);
        $batch = $request->integer('batch', 5);

        $result = $this->auditService->auditBatch($this->channel, $offset, $batch);
        $auditConfig = $this->auditService->configuredSlots($this->channel);

        $rows = $result['products']->map(fn ($product) => [
            'id' => $product->id,
            'html' => view('tools.listings._product-row', [
                'product' => $product,
                'channel' => $this->channel,
                'auditConfig' => $auditConfig,
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

        return redirect()->route('tools.sc-listings.index')->with('status', 'Audit column configuration updated.');
    }

    /** No-JS fallback — blocks for the whole report cycle, same as before this tool had a progress bar. */
    public function syncProducts(Request $request): RedirectResponse
    {
        try {
            $count = $this->syncFromReport();
        } catch (Throwable $e) {
            return redirect()->route('tools.sc-listings.index')->with('error', 'Sync failed: '.$e->getMessage());
        }

        return redirect()->route('tools.sc-listings.index')->with('status', "Synced {$count} listing(s) from Seller Central.");
    }

    /** Kicks off the report — the client then polls syncPoll() so it can show real progress instead of blocking. */
    public function syncStart(Request $request): JsonResponse
    {
        try {
            $create = $this->client->createReport('GET_MERCHANT_LISTINGS_ALL_DATA', $this->channel);
            if (($create['status'] ?? null) !== 202 || empty($create['body']['reportId'])) {
                throw new RuntimeException('Failed to create report (HTTP '.($create['status'] ?? 'unknown').').');
            }
        } catch (Throwable $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'processing', 'reportId' => $create['body']['reportId']]);
    }

    /** Checked out-of-band from Amazon's own report queue — a report has no finer-grained progress than "still processing" until it's DONE, so each poll is a single cheap status check, not a blocking wait. */
    public function syncPoll(Request $request): JsonResponse
    {
        $reportId = (string) $request->input('reportId');

        try {
            $report = $this->client->getReport($reportId, $this->channel);
            $processingStatus = $report['body']['processingStatus'] ?? null;

            if (! in_array($processingStatus, ['DONE', 'CANCELLED', 'FATAL'], true)) {
                return response()->json(['status' => 'processing', 'processingStatus' => $processingStatus]);
            }

            if ($processingStatus !== 'DONE') {
                return response()->json(['status' => 'failed', 'message' => "Report did not complete (status: {$processingStatus})."], 500);
            }

            $docId = $report['body']['reportDocumentId'] ?? null;
            if ($docId === null) {
                return response()->json(['status' => 'failed', 'message' => 'Report finished with no document ID.'], 500);
            }

            $count = $this->importReportDocument($docId);
        } catch (Throwable $e) {
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['status' => 'done', 'count' => $count]);
    }

    protected function syncFromReport(): int
    {
        $create = $this->client->createReport('GET_MERCHANT_LISTINGS_ALL_DATA', $this->channel);
        if (($create['status'] ?? null) !== 202 || empty($create['body']['reportId'])) {
            throw new RuntimeException('Failed to create report (HTTP '.($create['status'] ?? 'unknown').').');
        }

        $report = $this->client->waitForReport($create['body']['reportId'], $this->channel, maxWaitSeconds: 300, pollIntervalSeconds: 8);
        if (($report['body']['processingStatus'] ?? null) !== 'DONE') {
            throw new RuntimeException('Report did not complete (status: '.($report['body']['processingStatus'] ?? 'unknown').').');
        }

        $docId = $report['body']['reportDocumentId'] ?? null;
        if ($docId === null) {
            throw new RuntimeException('Report finished with no document ID.');
        }

        return $this->importReportDocument($docId);
    }

    protected function importReportDocument(string $docId): int
    {
        $content = $this->client->downloadReportDocument($docId, $this->channel);
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', (string) array_shift($lines));
        $header = explode("\t", $headerLine);

        $skuIdx = array_search('seller-sku', $header, true);
        $asinIdx = array_search('asin1', $header, true);
        $titleIdx = array_search('item-name', $header, true);
        $statusIdx = array_search('status', $header, true);
        if ($skuIdx === false) {
            // Without it every row would be read from the first column, and the
            // step below would mark every real SKU as removed.
            throw new RuntimeException('The listings report has no seller-sku column.');
        }

        $count = 0;
        $seen = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = explode("\t", $line);
            $sku = trim($cols[$skuIdx] ?? '');
            if ($sku === '') {
                continue;
            }

            ScProduct::updateOrCreate(['sku' => $sku], [
                'asin' => trim($cols[$asinIdx] ?? ''),
                'title' => trim($cols[$titleIdx] ?? ''),
                'status' => trim($cols[$statusIdx] ?? '') ?: 'Active',
                'synced_at' => now(),
            ]);
            $seen[] = $sku;
            $count++;
        }

        // Found issue 15: the all-listings report lists every SKU the account
        // has, active or not, so one that's missing has been deleted from
        // Seller Central. It drops out of the picker; its audits and edit
        // history stay. It comes back if a later report has it again.
        if ($seen !== []) {
            ScProduct::query()->whereNotIn('sku', $seen)->where('status', '!=', 'Removed')
                ->update(['status' => 'Removed', 'synced_at' => now()]);
        }

        return $count;
    }
}
