<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\ImageAudit;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\AttributeEngine;
use App\Services\AmazonListings\ImageAuditService;
use App\Services\AmazonListings\ListingChannel;
use App\Support\ExternalWrites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class ImageManagerController extends Controller
{
    public function __construct(
        protected SpApiClient $client,
        protected ImageAuditService $auditService,
    ) {}

    public function index(Request $request): View|Response
    {
        $products = Product::query()->active()->with('latestImageAudit')->orderBy('title')->get();

        $selectedAsin = (string) $request->query('asin', '');
        $selectedProduct = $selectedAsin !== '' ? $products->firstWhere('asin', $selectedAsin) : null;

        $gridData = null;
        $catalogError = null;
        if ($selectedProduct) {
            try {
                $gridData = $this->loadGridData($selectedProduct);
            } catch (Throwable $e) {
                $catalogError = $e->getMessage();
            }
        }

        if ($request->boolean('ajax')) {
            if ($catalogError) {
                return response('<p class="text-danger">'.e($catalogError).'</p>');
            }

            return response()->view('tools.image-manager._grid', ($gridData ?? []) + [
                'uploadRoute' => route('tools.vc-image-manager.upload'),
                'removeRoute' => route('tools.vc-image-manager.remove'),
            ]);
        }

        return view('tools.image-manager.index', [
            'writesEnabled' => ExternalWrites::allowed('listings'),
            'products' => $products,
            'selectedAsin' => $selectedAsin,
            'gridData' => $gridData,
            'catalogError' => $catalogError,
            'lastFullAudit' => ($max = ImageAudit::max('checked_at')) ? Carbon::parse($max) : null,
        ]);
    }

    protected function loadGridData(Product $product): array
    {
        $catalogResult = $this->client->getCatalogItem($product->asin, ListingChannel::Vendor);
        $images = $catalogResult['body']['images'][0]['images'] ?? [];
        $deduped = AttributeEngine::dedupeImagesByVariant($images);
        $liveByVariant = collect($deduped)->keyBy('variant');

        // Computed live on every row-expand (not read from the last saved
        // audit) — same "always fetch live" philosophy as the VC Listings
        // editor, since the stored audit could be stale.
        $listingsResult = $this->client->getListingsItem($product->sku, ListingChannel::Vendor);
        $liveAttributes = $listingsResult['body']['attributes'] ?? [];
        $slotStatuses = $this->auditService->computeSlotStatuses($product, $liveAttributes);

        return [
            'product' => $product,
            'liveByVariant' => $liveByVariant,
            'slotStatuses' => $slotStatuses,
            'imageAttributeMap' => ImageAuditService::IMAGE_ATTRIBUTE_MAP,
        ];
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'asin' => ['required', 'string'],
            'variant' => ['required', 'string', 'in:'.implode(',', array_keys(ImageAuditService::IMAGE_ATTRIBUTE_MAP))],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,tiff', 'max:10240'],
        ]);

        $asin = (string) $request->input('asin');
        $variant = (string) $request->input('variant');
        $attrKey = ImageAuditService::IMAGE_ATTRIBUTE_MAP[$variant];
        $product = Product::where('asin', $asin)->firstOrFail();

        $file = $request->file('image');
        $path = $file->store("vc-image-manager/{$asin}", 'public');
        $url = Storage::disk('public')->url($path);

        $patch = ['op' => 'replace', 'path' => "/attributes/{$attrKey}", 'value' => [[
            'media_location' => $url,
            'marketplace_id' => ListingChannel::Vendor->marketplaceId(),
        ]]];

        return $this->submitPatch($product, $attrKey, $patch, $path);
    }

    public function remove(Request $request): RedirectResponse
    {
        $request->validate([
            'asin' => ['required', 'string'],
            'variant' => ['required', 'string', 'in:'.implode(',', array_keys(ImageAuditService::IMAGE_ATTRIBUTE_MAP))],
        ]);

        $asin = (string) $request->input('asin');
        $variant = (string) $request->input('variant');
        $attrKey = ImageAuditService::IMAGE_ATTRIBUTE_MAP[$variant];
        $product = Product::where('asin', $asin)->firstOrFail();

        $patch = ['op' => 'replace', 'path' => "/attributes/{$attrKey}", 'value' => []];

        return $this->submitPatch($product, $attrKey, $patch);
    }

    /**
     * Same validate-preview -> real-submit -> ListingEdit log sequence as
     * VendorListingController::submit(), scoped to a single attribute.
     *
     * $storedPath is an upload's file on the public disk. Amazon needs its
     * URL to validate, so it is stored first; whenever Amazon won't be using
     * it, it is deleted again (found issue 22): the listing couldn't be
     * loaded, the preview failed or was rejected, writes are off, or the
     * submission came back not accepted. It is kept when the submission call
     * itself failed, since Amazon may still have taken it.
     */
    protected function submitPatch(Product $product, string $attrKey, array $patch, ?string $storedPath = null): RedirectResponse
    {
        $asin = $product->asin;
        $sku = $product->sku;
        $discard = function () use ($storedPath): void {
            if ($storedPath !== null) {
                Storage::disk('public')->delete($storedPath);
            }
        };

        try {
            $listingsResult = $this->client->getListingsItem($sku, ListingChannel::Vendor);
            $productType = $listingsResult['body']['productType'] ?? null;
            $oldValue = $listingsResult['body']['attributes'][$attrKey] ?? [];
        } catch (Throwable $e) {
            $discard();

            return redirect()->route('tools.vc-image-manager.index', ['asin' => $asin])->with('error', 'Could not load this listing: '.$e->getMessage());
        }

        if (! $productType) {
            $discard();

            return redirect()->route('tools.vc-image-manager.index', ['asin' => $asin])->with('error', 'Could not determine this listing\'s product type.');
        }

        try {
            $preview = $this->client->patchListing($sku, $productType, [$patch], true, ListingChannel::Vendor);
        } catch (Throwable $e) {
            $discard();

            return redirect()->route('tools.vc-image-manager.index', ['asin' => $asin])->with('error', 'Validation call failed: '.$e->getMessage());
        }

        $previewIssues = array_filter($preview['body']['issues'] ?? [], fn ($i) => ($i['severity'] ?? '') === 'ERROR');
        if (($preview['status'] ?? null) !== 200 || ($preview['body']['status'] ?? null) !== 'VALID' || ! empty($previewIssues)) {
            $messages = array_map(fn ($i) => $i['message'] ?? 'unknown issue', $preview['body']['issues'] ?? []);
            $discard();

            return redirect()->route('tools.vc-image-manager.index', ['asin' => $asin])
                ->with('error', 'Amazon rejected this change during validation: '.implode('; ', $messages ?: ['HTTP '.$preview['status']]));
        }

        // Plan section 2, rule 3: until cutover only the preview runs. Nothing
        // is submitted or logged, and the uploaded file isn't kept.
        if (! ExternalWrites::allowed('listings')) {
            $discard();

            return redirect()->route('tools.vc-image-manager.index', ['asin' => $asin])->with('warning', __(
                "Amazon's validation preview accepted this change to :attribute. Submissions are off in this app until cutover, so nothing was sent to Amazon and nothing was logged. Make this change in the old app.",
                ['attribute' => $attrKey],
            ));
        }

        try {
            $real = $this->client->patchListing($sku, $productType, [$patch], false, ListingChannel::Vendor);
        } catch (Throwable $e) {
            return redirect()->route('tools.vc-image-manager.index', ['asin' => $asin])->with('error', 'Submission call failed: '.$e->getMessage());
        }

        $status = $real['body']['status'] ?? 'REJECTED';

        ListingEdit::create([
            'product_id' => $product->id,
            'user_id' => request()->user()->id,
            'asin' => $asin,
            'sku' => $sku,
            'attribute' => $attrKey,
            'old_value' => json_encode($oldValue),
            'new_value' => json_encode($patch['value']),
            'submission_id' => $real['body']['submissionId'] ?? null,
            'status' => $status,
            'issues_json' => json_encode($real['body']['issues'] ?? []),
        ]);

        if (($real['status'] ?? null) !== 200 || $status !== 'ACCEPTED') {
            $discard();

            return redirect()->route('tools.vc-image-manager.index', ['asin' => $asin])->with('error', "Submission was not accepted (status: {$status}).");
        }

        return redirect()->route('tools.vc-image-manager.index', ['asin' => $asin])->with(
            'status',
            'Image submitted — accepted by Amazon (submission '.($real['body']['submissionId'] ?? '?').'). Changes are applied asynchronously and may take a few minutes to appear live.'
        );
    }

    public function auditRun(Request $request): RedirectResponse
    {
        set_time_limit(600);
        ['checked' => $checked, 'failed' => $failed] = $this->auditService->auditAll();

        $message = "Audit complete — checked {$checked} product(s)";
        $message .= $failed > 0 ? ", {$failed} failed to fetch." : '.';

        return redirect()->route('tools.vc-image-manager.index')->with('status', $message);
    }

    public function auditBatch(Request $request): JsonResponse
    {
        set_time_limit(60);
        $offset = $request->integer('offset', 0);
        $batch = $request->integer('batch', 5);

        $result = $this->auditService->auditBatch($offset, $batch);

        $rows = $result['products']->map(fn ($product) => [
            'id' => $product->id,
            'html' => view('tools.image-manager._product-row', [
                'product' => $product->refresh(),
            ])->render(),
        ])->values();
        unset($result['products']);

        if ($result['done']) {
            $max = ImageAudit::max('checked_at');
            $result['lastFullAudit'] = $max ? Carbon::parse($max)->format('Y-m-d H:i:s T') : null;
        }

        return response()->json($result + ['rows' => $rows]);
    }
}
