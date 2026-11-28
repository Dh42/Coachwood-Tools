<?php

namespace App\Services\AmazonListings;

use App\Models\ImageAudit;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Services\Amazon\SpApiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * VC-only by design (no ListingChannel parameter) — this tool was scoped to
 * Vendor Central only, unlike ListingAuditService which serves both
 * channels. One getListingsItem call per product is enough: image-locator
 * attribute values are compared the same way ListingAuditService compares
 * every other attribute (AttributeEngine::sameValue against our own last
 * accepted submission), not against Catalog Items' rendered/re-hosted URLs,
 * which would never match even a successful submission.
 */
class ImageAuditService
{
    /**
     * Amazon's documented SP-API convention for image-locator attribute
     * keys and their corresponding Catalog Items image variant codes. This
     * is genuinely the full set the SP-API can read or write — Vendor
     * Central's own UI additionally shows FRNT/SIDE/BACK (and possibly
     * others) slots, but Amazon has confirmed those aren't accessible via
     * either the Catalog Items or Listings Items API at all (an open
     * feature request as of mid-2026, see
     * https://github.com/amzn/selling-partner-api-models/discussions/4924)
     * — not a gap in this tool, a platform limitation. The exact key/variant
     * pairing itself is still unverified against a live response in this
     * environment (no local SP-API credentials); confirm once deployed.
     */
    public const IMAGE_ATTRIBUTE_MAP = [
        'MAIN' => 'main_product_image_locator',
        'PT01' => 'other_product_image_locator_1',
        'PT02' => 'other_product_image_locator_2',
        'PT03' => 'other_product_image_locator_3',
        'PT04' => 'other_product_image_locator_4',
        'PT05' => 'other_product_image_locator_5',
        'PT06' => 'other_product_image_locator_6',
        'PT07' => 'other_product_image_locator_7',
        'PT08' => 'other_product_image_locator_8',
    ];

    public function __construct(private readonly SpApiClient $client) {}

    private function lastAcceptedEditsByAttribute(Product $product): Collection
    {
        $edits = ListingEdit::query()
            ->where('asin', $product->asin)
            ->where('status', 'ACCEPTED')
            ->whereIn('attribute', array_values(self::IMAGE_ATTRIBUTE_MAP))
            ->orderBy('created_at')
            ->get(['attribute', 'new_value']);

        // Ascending order, so the last row per attribute is the most recent submission.
        return $edits->groupBy('attribute')->map(fn (Collection $rows) => $rows->last());
    }

    /**
     * Single source of truth for per-slot status, comparing our own last
     * accepted submission against Amazon's live getListingsItem attribute
     * value — not against Catalog Items' rendered/re-hosted URLs, which
     * would never match even a successful submission. "Live" therefore
     * depends on getListingsItem echoing back the same media_location we
     * submitted, unverified locally (no SP-API credentials in this
     * environment) — if that assumption is wrong once deployed, everything
     * would show not_live even when actually live, and this would need a
     * different signal.
     *
     * @return array<string, array{status: 'live'|'not_live'|'not_ours'|'empty', ours_url: ?string}>
     */
    public function computeSlotStatuses(Product $product, array $liveAttributes): array
    {
        $lastSubmitted = $this->lastAcceptedEditsByAttribute($product);

        $statuses = [];
        foreach (self::IMAGE_ATTRIBUTE_MAP as $variant => $attrKey) {
            $live = $liveAttributes[$attrKey] ?? [];
            $hasLive = ! empty($live);

            $row = $lastSubmitted->get($attrKey);
            $ours = $row ? (json_decode($row->new_value, true) ?? []) : null;
            $oursUrl = $ours[0]['media_location'] ?? null;

            $status = match (true) {
                $ours === null && ! $hasLive => 'empty',
                $ours === null && $hasLive => 'not_ours',
                $hasLive && AttributeEngine::sameValue($ours, $live) => 'live',
                default => 'not_live',
            };

            $statuses[$variant] = ['status' => $status, 'ours_url' => $oursUrl];
        }

        return $statuses;
    }

    /**
     * @return array<string> variant codes (e.g. ['MAIN', 'PT02']) that are
     *                       live but not our contribution
     */
    public function computeUnattributedVariants(Product $product, array $liveAttributes): array
    {
        return $this->variantsWithStatus($product, $liveAttributes, 'not_ours');
    }

    /**
     * @return array<string> variant codes we have an accepted submission for,
     *                       but which don't currently match what's live
     */
    public function computeNotLiveVariants(Product $product, array $liveAttributes): array
    {
        return $this->variantsWithStatus($product, $liveAttributes, 'not_live');
    }

    private function variantsWithStatus(Product $product, array $liveAttributes, string $wanted): array
    {
        $statuses = $this->computeSlotStatuses($product, $liveAttributes);

        return array_keys(array_filter($statuses, fn ($s) => $s['status'] === $wanted));
    }

    public function auditOne(Product $product): bool
    {
        $error = null;
        $unattributed = [];
        $notLive = [];

        try {
            $result = $this->client->getListingsItem($product->sku, ListingChannel::Vendor);
            if (($result['status'] ?? null) !== 200) {
                throw new \RuntimeException('getListingsItem returned HTTP '.($result['status'] ?? 'unknown'));
            }

            $statuses = $this->computeSlotStatuses($product, $result['body']['attributes'] ?? []);
            $unattributed = array_keys(array_filter($statuses, fn ($s) => $s['status'] === 'not_ours'));
            $notLive = array_keys(array_filter($statuses, fn ($s) => $s['status'] === 'not_live'));
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        ImageAudit::create([
            'product_id' => $product->id,
            'unattributed_variants' => $unattributed === [] ? null : implode(',', $unattributed),
            'unattributed_count' => $error === null ? count($unattributed) : null,
            'not_live_variants' => $notLive === [] ? null : implode(',', $notLive),
            'not_live_count' => $error === null ? count($notLive) : null,
            'fetch_error' => $error,
            'checked_at' => now(),
        ]);

        return $error === null;
    }

    /**
     * Synchronous, no-JavaScript fallback: audits every active product in one
     * request, with the same pause between products as auditBatch(). The old
     * controller looped with no pause at all (found issue 21).
     *
     * @return array{checked: int, failed: int}
     */
    public function auditAll(): array
    {
        $products = Product::query()->active()->orderBy('id')->get();

        $checked = 0;
        $failed = 0;
        foreach ($products as $i => $product) {
            $this->auditOne($product) ? $checked++ : $failed++;
            if ($i < $products->count() - 1) {
                Sleep::usleep(550_000);
            }
        }

        return ['checked' => $checked, 'failed' => $failed];
    }

    /**
     * @return array{processed: int, checked: int, failed: int, nextOffset: int, total: int, done: bool, products: Collection}
     */
    public function auditBatch(int $offset, int $batch): array
    {
        $batch = min(10, max(1, $batch));

        $total = Product::query()->active()->count();
        $products = Product::query()->active()->orderBy('id')->skip($offset)->take($batch)->get();

        $checked = 0;
        $failed = 0;
        foreach ($products as $i => $product) {
            $this->auditOne($product) ? $checked++ : $failed++;
            if ($i < $products->count() - 1) {
                usleep(550_000);
            }
        }

        $nextOffset = $offset + $products->count();

        return [
            'processed' => $products->count(),
            'checked' => $checked,
            'failed' => $failed,
            'nextOffset' => $nextOffset,
            'total' => $total,
            'done' => $nextOffset >= $total,
            'products' => $products,
        ];
    }
}
