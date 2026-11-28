<?php

namespace App\Services\AmazonListings;

use App\Services\Amazon\SpApiClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * One audit service for both channels, parameterized by ListingChannel —
 * this is what actually eliminates the code-duplication problem the legacy
 * PHP tools had (two nearly-identical files), not just moving the
 * duplication into two controllers instead.
 */
class ListingAuditService
{
    /**
     * 5 synthetic pseudo-attributes representing nested nutrition facts, so
     * they can sit alongside real top-level attribute keys in the
     * configurable column picker.
     */
    public const NUTRITION_PSEUDO_ATTRIBUTES = [
        'nutrition.serving_quantity' => 'Nutrition: Serving Quantity',
        'nutrition.energy_content' => 'Nutrition: Calories',
        'nutrition.total_fat' => 'Nutrition: Total Fat',
        'nutrition.total_carbohydrate' => 'Nutrition: Total Carbohydrate',
        'nutrition.protein' => 'Nutrition: Protein',
    ];

    public const DEFAULT_AUDIT_SLOTS = [
        'nutrition.serving_quantity', 'nutrition.energy_content', 'nutrition.total_fat',
        'nutrition.total_carbohydrate', 'nutrition.protein', 'bullet_point', 'regulatory_compliance_certification',
    ];

    public function __construct(private readonly SpApiClient $client) {}

    /**
     * Runs all 4 SP-API calls for one product and appends a new audit row.
     * The first 3 are independently try/caught; all 3 always run regardless
     * of an earlier failure, but only the first failure's message is
     * recorded as fetch_error and counted toward "X failed to fetch" in the
     * UI. The 4th (Listings Restrictions) is deliberately NOT folded into
     * that same error/count — Amazon's own docs never confirm vendor
     * support for that endpoint (see SpApiClient::getListingsRestrictions()),
     * so a failure there is an expected, acceptable outcome the caller
     * asked for anyway, not a real audit failure.
     */
    public function auditOne(ListingChannel $channel, Model $product): bool
    {
        $configuredSlots = $this->configuredSlots($channel);
        $error = null;

        $flags = [
            'has_serving_quantity' => false, 'has_energy_content' => false, 'has_total_fat' => false,
            'has_total_carbohydrate' => false, 'has_protein' => false, 'bullet_point_count' => 0,
            'has_regulatory_compliance' => false, 'brand_code' => null, 'manufacturer_code' => null,
            'replenishment_category' => null, 'aplus_status' => null, 'image_count' => null,
            'contribution_mismatch_count' => null, 'contribution_mismatch_attrs' => null,
            'audited_attributes' => null, 'listing_issues' => null, 'listing_restrictions' => null,
        ];

        try {
            $listingsResult = $this->client->getListingsItem($product->sku, $channel);
            if (($listingsResult['status'] ?? null) !== 200) {
                throw new \RuntimeException('getListingsItem returned HTTP '.($listingsResult['status'] ?? 'unknown'));
            }
            $attributes = $listingsResult['body']['attributes'] ?? [];
            // Already being fetched on every audit run (includedData
            // already asks for issues, has since this method was built for
            // the edit-submission validation path) — just persisting it
            // here now, not a new SP-API call. Genuinely channel-agnostic,
            // unlike replenishment_category — issues works the same for
            // both Vendor and Seller.
            $flags['listing_issues'] = $listingsResult['body']['issues'] ?? [];

            $flags = [...$flags, ...$this->computeAuditFlags($attributes)];
            // Assign the plain array, not json_encode($configured) — the
            // model already array-casts this column, so pre-encoding it
            // double-encodes (Eloquent's array-cast setter always
            // json_encodes whatever it's given, string or not). This exact
            // mistake was already fixed once, in the legacy-data import
            // command — that fix never touched this live path, which has
            // been double-encoding every audited_attributes value written
            // by a real "Audit Listings" run since the tool was built,
            // masked until now because most of the table's data came from
            // that already-corrected import, not a live run. See
            // listings:repair-double-encoded-audited-attributes.
            $configured = $this->computeConfiguredAuditFlags($configuredSlots, $attributes);
            $flags['audited_attributes'] = $configured;

            $this->recordAttributeSightings($channel, array_keys($attributes));

            $mismatch = $this->computeContributionMismatch($channel, $product, $attributes);
            $flags['contribution_mismatch_count'] = $mismatch['count'];
            $flags['contribution_mismatch_attrs'] = $mismatch['attrs'] === [] ? null : implode(',', $mismatch['attrs']);
        } catch (Throwable $e) {
            $error ??= $e->getMessage();
        }

        try {
            $catalogResult = $this->client->getCatalogItem($product->asin, $channel);
            if (($catalogResult['status'] ?? null) !== 200) {
                throw new \RuntimeException('getCatalogItem returned HTTP '.($catalogResult['status'] ?? 'unknown'));
            }

            // vendorDetails is only ever requested for the Vendor channel in
            // the first place (SpApiClient::getCatalogItem() — it hard-400s
            // for a seller-scoped token), but images is requested for both
            // channels, so counting them shouldn't be gated the same way.
            if ($channel->includeVendorDetails()) {
                $vendorDetails = $catalogResult['body']['vendorDetails'][0] ?? [];
                $flags['brand_code'] = $vendorDetails['brandCode'] ?? null;
                $flags['manufacturer_code'] = $vendorDetails['manufacturerCode'] ?? null;
                $flags['replenishment_category'] = $vendorDetails['replenishmentCategory'] ?? null;
            }

            // Catalog Items returns every image variant (MAIN, PT01, ...) at
            // several resolutions each as separate array entries — a raw
            // count triples or worse (confirmed live against the legacy
            // tool's counts for the same real ASINs). See
            // AttributeEngine::dedupeImagesByVariant().
            $images = $catalogResult['body']['images'][0]['images'] ?? [];
            $flags['image_count'] = count(AttributeEngine::dedupeImagesByVariant($images));
        } catch (Throwable $e) {
            $error ??= $e->getMessage();
        }

        try {
            $aplusResult = $this->client->getAplusPublishRecords($product->asin, $channel);
            if (($aplusResult['status'] ?? null) === 200) {
                $records = $aplusResult['body']['publishRecordList'] ?? [];
                $hasReal = false;
                foreach ($records as $record) {
                    if (($record['contentType'] ?? '') === $channel->realAplusContentType()) {
                        $hasReal = true;
                        break;
                    }
                }
                $flags['aplus_status'] = $hasReal ? 'full' : (! empty($records) ? 'brand_only' : 'none');
            }
        } catch (Throwable $e) {
            $error ??= $e->getMessage();
        }

        try {
            $restrictionsResult = $this->client->getListingsRestrictions($product->asin, $channel);
            if (($restrictionsResult['status'] ?? null) === 200) {
                $flags['listing_restrictions'] = $restrictionsResult['body']['restrictions'] ?? [];
            }
            // A non-200 here stays null (never assigned) rather than [] —
            // "we don't know" is a different, more honest state than
            // "Amazon confirmed no restrictions," same null-vs-empty-array
            // distinction listing_issues already relies on.
        } catch (Throwable) {
            // Not folded into $error — see this method's docblock.
        }

        $flags['fetch_error'] = $error;
        $flags['checked_at'] = now();
        $flags['product_id'] = $product->id;

        $channel->listingAuditModel()::create($flags);

        return $error === null;
    }

    /**
     * @return array{checked: int, failed: int, nextOffset: int, total: int, done: bool, products: Collection}
     */
    public function auditBatch(ListingChannel $channel, int $offset, int $batch): array
    {
        $batch = min(10, max(1, $batch));
        $productModel = $channel->productModel();

        $total = $productModel::query()->active()->count();
        $products = $productModel::query()->active()->orderBy('id')->skip($offset)->take($batch)->get();

        $checked = 0;
        $failed = 0;
        foreach ($products as $i => $product) {
            $this->auditOne($channel, $product) ? $checked++ : $failed++;
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
            // Relations aren't eager-loaded here — the controller renders
            // each one's fresh row HTML for the real-time UI update, and a
            // lazy-load on ->latestListingAudit at that point correctly
            // picks up the audit row auditOne() just created (not a stale
            // pre-audit snapshot), since these model instances never had
            // the relation loaded in the first place.
            'products' => $products,
        ];
    }

    /**
     * Synchronous, no-JS fallback — audits every active product in one request.
     */
    public function auditAll(ListingChannel $channel): array
    {
        $productModel = $channel->productModel();
        $products = $productModel::query()->active()->orderBy('id')->get();

        $checked = 0;
        $failed = 0;
        foreach ($products as $i => $product) {
            $this->auditOne($channel, $product) ? $checked++ : $failed++;
            if ($i < $products->count() - 1) {
                usleep(550_000);
            }
        }

        return ['checked' => $checked, 'failed' => $failed];
    }

    private function computeAuditFlags(array $attributes): array
    {
        $ni = $attributes['nutritional_info'][0] ?? [];

        return [
            'has_serving_quantity' => isset($ni['serving_quantity']),
            'has_energy_content' => isset($ni['energy'][0]['content']),
            'has_total_fat' => isset($ni['fat'][0]['total']),
            'has_total_carbohydrate' => isset($ni['carbohydrate'][0]['total']),
            'has_protein' => isset($ni['protein'][0]['value']),
            'bullet_point_count' => count($attributes['bullet_point'] ?? []),
            'has_regulatory_compliance' => ! empty($attributes['regulatory_compliance_certification']),
        ];
    }

    private function listingAuditExtractValue(string $key, array $attributes): array
    {
        if ($key === 'bullet_point') {
            return ['kind' => 'count', 'value' => count($attributes['bullet_point'] ?? [])];
        }

        if (str_starts_with($key, 'nutrition.')) {
            $ni = $attributes['nutritional_info'][0] ?? [];
            $present = match ($key) {
                'nutrition.serving_quantity' => isset($ni['serving_quantity']),
                'nutrition.energy_content' => isset($ni['energy'][0]['content']),
                'nutrition.total_fat' => isset($ni['fat'][0]['total']),
                'nutrition.total_carbohydrate' => isset($ni['carbohydrate'][0]['total']),
                'nutrition.protein' => isset($ni['protein'][0]['value']),
                default => false,
            };

            return ['kind' => 'presence', 'value' => $present];
        }

        return ['kind' => 'presence', 'value' => ! empty($attributes[$key] ?? null)];
    }

    private function computeConfiguredAuditFlags(array $slots, array $attributes): array
    {
        $out = [];
        foreach ($slots as $key) {
            $out[$key] = $this->listingAuditExtractValue($key, $attributes);
        }

        return $out;
    }

    private function recordAttributeSightings(ListingChannel $channel, array $attributeKeys): void
    {
        if (empty($attributeKeys)) {
            return;
        }

        $model = $channel->attributeCatalogModel();
        foreach ($attributeKeys as $key) {
            $record = $model::firstOrNew(['attribute_key' => $key]);
            if (! $record->exists) {
                $record->first_seen_at = now();
            }
            $record->last_seen_at = now();
            $record->save();
        }
    }

    /**
     * @return array{count: ?int, attrs: array<string>} count is null if this
     *                                                  ASIN has never had an accepted submission — distinct from 0 (means
     *                                                  verified matches).
     */
    private function computeContributionMismatch(ListingChannel $channel, Model $product, array $liveAttributes): array
    {
        $editModel = $channel->listingEditModel();

        $edits = $editModel::query()
            ->where('asin', $product->asin)
            ->where('status', 'ACCEPTED')
            ->orderBy('created_at')
            ->get(['attribute', 'new_value']);

        if ($edits->isEmpty()) {
            return ['count' => null, 'attrs' => []];
        }

        // Ascending order, so the last row per attribute is the most recent submission.
        $lastSubmitted = $edits->groupBy('attribute')->map(fn ($rows) => $rows->last());

        $mismatched = [];
        foreach ($lastSubmitted as $attribute => $row) {
            $ours = json_decode($row->new_value, true) ?? [];
            $live = $liveAttributes[$attribute] ?? [];
            if (! AttributeEngine::sameValue($ours, $live)) {
                $mismatched[] = $attribute;
            }
        }

        return ['count' => count($mismatched), 'attrs' => $mismatched];
    }

    /**
     * The current (latest effective_from) up-to-7 audit column slots, or the
     * original 7 legacy defaults if no config has ever been saved.
     */
    public function configuredSlots(ListingChannel $channel): array
    {
        $config = $channel->listingAuditConfigModel()::query()->orderByDesc('effective_from')->orderByDesc('id')->first();

        return $config ? $config->slots() : self::DEFAULT_AUDIT_SLOTS;
    }

    public function configuredAliases(ListingChannel $channel): array
    {
        $config = $channel->listingAuditConfigModel()::query()->orderByDesc('effective_from')->orderByDesc('id')->first();

        return $config ? $config->aliases() : [];
    }

    /**
     * The raw 7 slots (gaps included, position preserved) for the "Configure
     * audit columns" form's own pre-population — see
     * ListingAuditConfig::rawSlots() for why this must stay separate from
     * configuredSlots().
     */
    public function configuredRawSlots(ListingChannel $channel): array
    {
        $config = $channel->listingAuditConfigModel()::query()->orderByDesc('effective_from')->orderByDesc('id')->first();

        return $config ? $config->rawSlots() : array_pad(self::DEFAULT_AUDIT_SLOTS, 7, null);
    }

    /** The raw 7 alias slots (position preserved) — see configuredRawSlots(). */
    public function configuredRawAliases(ListingChannel $channel): array
    {
        $config = $channel->listingAuditConfigModel()::query()->orderByDesc('effective_from')->orderByDesc('id')->first();

        return $config ? $config->rawAliases() : array_fill(0, 7, null);
    }

    /**
     * Every attribute key that's actually been seen on a real product, plus
     * the 5 synthetic nutrition pseudo-keys — the picker's dropdown options.
     *
     * @return array<string, string> key => label
     */
    public function availableAttributes(ListingChannel $channel): array
    {
        $seen = $channel->attributeCatalogModel()::query()->orderBy('attribute_key')->pluck('attribute_key');

        $options = self::NUTRITION_PSEUDO_ATTRIBUTES;
        foreach ($seen as $key) {
            $options[$key] = AttributeEngine::humanizeKey($key);
        }

        return array_map(AttributeEngine::displayLabel(...), $options);
    }

    public function updateConfig(ListingChannel $channel, array $slots, array $aliases, ?string $reason, ?int $userId): void
    {
        $validKeys = array_keys($this->availableAttributes($channel));

        $data = ['effective_from' => now(), 'reason' => $reason ?: null, 'changed_by_user_id' => $userId];
        for ($i = 0; $i < 7; $i++) {
            $value = trim((string) ($slots[$i] ?? ''));
            $key = ($value !== '' && in_array($value, $validKeys, true)) ? $value : null;
            $data['attr_slot_'.($i + 1)] = $key;
            $data['alias_slot_'.($i + 1)] = $key !== null ? (trim((string) ($aliases[$i] ?? '')) ?: null) : null;
        }

        $channel->listingAuditConfigModel()::create($data);
    }
}
