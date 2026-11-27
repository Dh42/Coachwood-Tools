<?php

namespace App\Services\Cdq;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqDefect;
use App\Models\CdqListingSnapshot;
use App\Models\CdqProductTypeSchema;
use App\Models\CdqScore;
use App\Models\CdqSearchCriticalAttribute;
use App\Models\CdqTitleLengthBand;
use Illuminate\Support\Collection;

/**
 * CDQ Phase 3 — deterministic scoring engine. Pure and replayable: every
 * input comes from the stored snapshot (catalog_json/attributes_json/
 * main_image_hash/aplus_json/issues_json) plus the check-config revision
 * passed in — no live SP-API or network calls happen here.
 *
 * Thresholds below are transcribed straight from the brief (bullet <80
 * chars thin, A+ <5 modules thin, image longest edge <1600px low-res)
 * except the title length band, which the brief specifies as "the product
 * type's own recommended min/max" — confirmed live that Product Type
 * Definitions doesn't actually expose one (only an unconstrained hard
 * maxLength), so that one is curated via cdq_title_length_band instead
 * (Doug-seeded, empty until populated — the check simply doesn't fire for
 * a product type with no row). PROMO_WORDS is a starter list, not from the
 * brief, meant to be curated further.
 */
class CdqScoringService
{
    private const BULLET_THIN_LENGTH = 80;

    private const APLUS_THIN_MODULE_COUNT = 5;

    private const IMAGE_LOW_RES_MAX_EDGE_PX = 1600;

    /** @var string[] lowercase substrings; not from the brief — a reasonable starter list, meant to be curated further. */
    private const PROMO_WORDS = ['free shipping', 'best seller', '% off', 'limited time', 'guarantee', 'sale price', 'lowest price'];

    private Collection $checks;

    public function __construct(private readonly CdqCheckConfigVersion $version)
    {
        $this->checks = $version->checks()->where('enabled', true)->get()->keyBy('check_code');
    }

    public function score(CdqListingSnapshot $snapshot): CdqScore
    {
        CdqDefect::where('snapshot_id', $snapshot->id)->delete();

        if ($snapshot->issues_checked_at !== null && $this->hasOpenPolicyViolation($snapshot)) {
            return $this->persist($snapshot, [], grade: 'U', total: null);
        }

        $schema = CdqProductTypeSchema::where('product_type', $snapshot->product_type)
            ->where('marketplace_id', $snapshot->marketplace_id)
            ->first();

        $defects = [
            ...($schema ? $this->checkAttr($snapshot, $schema) : []),
            ...$this->checkText($snapshot),
            ...$this->checkImage($snapshot),
            ...$this->checkAplus($snapshot),
        ];

        if ($this->hasGradeDDefect($snapshot, $defects)) {
            $this->storeDefects($snapshot, $defects);

            return $this->persist($snapshot, $defects, grade: 'D', total: 0);
        }

        $scores = $this->rollUp($defects);
        $total = round(array_sum($scores), 2);
        $grade = match (true) {
            $total >= 90 => 'A',
            $total >= 75 => 'B',
            $total >= 60 => 'C',
            default => 'D',
        };

        $this->storeDefects($snapshot, $defects);

        return $this->persist($snapshot, $defects, $grade, $total, $scores);
    }

    /**
     * Grade U (brief: NULL score for an open policy violation). Real
     * Listings Items issues carry severity WARNING|ERROR and, when Amazon
     * is actually acting on it, an enforcements.actions[] list (confirmed
     * live — e.g. ATTRIBUTE_SUPPRESSED) with an exemption.status that isn't
     * EXEMPT. A WARNING with no enforcement action is noise, not a
     * violation — only ERROR + an active, non-exempt enforcement counts.
     */
    private function hasOpenPolicyViolation(CdqListingSnapshot $snapshot): bool
    {
        foreach ($snapshot->issues_json ?? [] as $issue) {
            if (($issue['severity'] ?? null) !== 'ERROR') {
                continue;
            }
            $actions = $issue['enforcements']['actions'] ?? [];
            $exemptionStatus = $issue['enforcements']['exemption']['status'] ?? 'NOT_EXEMPT';
            if (! empty($actions) && $exemptionStatus !== 'EXEMPT') {
                return true;
            }
        }

        return false;
    }

    /**
     * Grade D (brief: 0 for egregious defects — main image / title / a
     * required-attribute-affecting-search). The curated
     * cdq_search_critical_attribute table is the second signal here
     * ("which listings are at risk" vs. issues_json's "which are actually
     * suppressed") — it's Doug-seeded and may be empty, in which case this
     * falls back to just main-image/title.
     */
    private function hasGradeDDefect(CdqListingSnapshot $snapshot, array $defects): bool
    {
        foreach ($defects as $defect) {
            if ($defect['check_code'] === 'MAIN_IMAGE_MISSING') {
                return true;
            }
        }

        if (empty($this->attributeValue($snapshot, 'item_name'))) {
            return true;
        }

        $critical = CdqSearchCriticalAttribute::where('marketplace_id', $snapshot->marketplace_id)
            ->where(function ($q) use ($snapshot) {
                $q->whereNull('product_type')->orWhere('product_type', $snapshot->product_type);
            })
            ->pluck('attribute_name');

        foreach ($critical as $attributeName) {
            if (empty($snapshot->attributes_json[$attributeName] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{check_code:string, dimension:string, severity:string, points:float, attribute_path:?string, detail:?array}> */
    private function checkAttr(CdqListingSnapshot $snapshot, CdqProductTypeSchema $schema): array
    {
        $attributes = $snapshot->attributes_json ?? [];
        $defects = [];

        foreach ($schema->required_json ?? [] as $name) {
            if (empty($attributes[$name] ?? null)) {
                $defects[] = $this->defect('ATTR_REQUIRED_MISSING', 'ATTR', attributePath: $name);
            }
        }

        foreach ($schema->recommended_json ?? [] as $name) {
            if (empty($attributes[$name] ?? null)) {
                $defects[] = $this->defect('ATTR_RECOMMENDED_MISSING', 'ATTR', attributePath: $name);
            }
        }

        // Scoped down per an explicit decision — not a full JSON Schema
        // validator. Only checks the two constraint types Product Type
        // Definitions actually expresses per attribute: an enum allow-list
        // and string min/maxLength, both read straight off raw_schema.
        foreach ($attributes as $name => $instances) {
            $propSchema = $schema->raw_schema['properties'][$name]['items']['properties']['value'] ?? null;
            if ($propSchema === null) {
                continue;
            }

            foreach ($instances as $instance) {
                $value = $instance['value'] ?? null;
                if ($value === null) {
                    continue;
                }

                $enum = $propSchema['enum'] ?? null;
                if ($enum !== null && ! in_array($value, $enum, true)) {
                    $defects[] = $this->defect('ATTR_SCHEMA_INVALID', 'ATTR', attributePath: $name, detail: ['reason' => 'not in enum', 'value' => $value]);

                    continue;
                }

                if (is_string($value)) {
                    $min = $propSchema['minLength'] ?? null;
                    $max = $propSchema['maxLength'] ?? null;
                    if (($min !== null && strlen($value) < $min) || ($max !== null && strlen($value) > $max)) {
                        $defects[] = $this->defect('ATTR_SCHEMA_INVALID', 'ATTR', attributePath: $name, detail: ['reason' => 'length out of bounds', 'length' => strlen($value)]);
                    }
                }
            }
        }

        return $defects;
    }

    /**
     * DESCRIPTION_MISSING/KEYWORDS_MISSING read rtip_product_description/
     * generic_keyword specifically — confirmed live across every synced
     * Vendor Central product type schema (DRINK_FLAVORED, FOOD,
     * HEALTH_PERSONAL_CARE, NUTRITIONAL_SUPPLEMENT, PROTEIN_DRINK) that
     * the generic Seller-Central-style names (product_description,
     * generic_keywords) simply don't exist on this account — rtip_ is
     * Vendor Central's own attribute naming for this field.
     */
    private function checkText(CdqListingSnapshot $snapshot): array
    {
        $defects = [];

        $title = $this->attributeValue($snapshot, 'item_name');
        if ($title !== null) {
            $length = strlen($title);
            $band = CdqTitleLengthBand::where('marketplace_id', $snapshot->marketplace_id)
                ->where('product_type', $snapshot->product_type)
                ->first();
            if ($band !== null && ($length < $band->min_length || $length > $band->max_length)) {
                $defects[] = $this->defect('TITLE_LENGTH_OUT_OF_BAND', 'TEXT', attributePath: 'item_name', detail: ['length' => $length, 'band' => [$band->min_length, $band->max_length]]);
            }
            $lowerTitle = strtolower($title);
            foreach (self::PROMO_WORDS as $word) {
                if (str_contains($lowerTitle, $word)) {
                    $defects[] = $this->defect('TITLE_PROMO_LANGUAGE', 'TEXT', attributePath: 'item_name', detail: ['matched' => $word]);
                    break;
                }
            }
        }

        $bullets = $snapshot->attributes_json['bullet_point'] ?? [];
        if (count($bullets) < 5) {
            $defects[] = $this->defect('BULLETS_UNDER_FIVE', 'TEXT', instances: 5 - count($bullets));
        }
        foreach ($bullets as $bullet) {
            if (strlen($bullet['value'] ?? '') < self::BULLET_THIN_LENGTH) {
                $defects[] = $this->defect('BULLET_THIN', 'TEXT');
            }
        }

        if (empty($this->attributeValue($snapshot, 'rtip_product_description'))) {
            $defects[] = $this->defect('DESCRIPTION_MISSING', 'TEXT', attributePath: 'rtip_product_description');
        }

        if (empty($snapshot->attributes_json['generic_keyword'] ?? null)) {
            $defects[] = $this->defect('KEYWORDS_MISSING', 'TEXT', attributePath: 'generic_keyword');
        }

        return $defects;
    }

    /**
     * VARIANT_IMAGE_SHARED compares main_image_hash across siblings sharing
     * the same parent_asin (the same query family this snapshot's own
     * parent_asin was resolved against in Phase 2) — a real byte-identical
     * hash match across children is the actual signal, not "images have
     * the same URL" (URLs differ even for a re-uploaded identical file).
     */
    private function checkImage(CdqListingSnapshot $snapshot): array
    {
        $defects = [];
        $images = $snapshot->catalog_json['images'][0]['images'] ?? [];
        $mainCount = count(array_filter($images, fn ($img) => ($img['variant'] ?? null) === 'MAIN'));

        if ($mainCount === 0) {
            $defects[] = $this->defect('MAIN_IMAGE_MISSING', 'IMAGE');
        }

        $distinctVariants = count(array_unique(array_column($images, 'variant')));
        if ($distinctVariants < 6) {
            $defects[] = $this->defect('IMAGE_COUNT_UNDER_SIX', 'IMAGE', instances: 6 - $distinctVariants);
        }

        // Only the largest instance of each variant represents the image
        // Amazon actually serves at zoom — thumbnails are expected to be
        // small and aren't a real defect.
        $largestPerVariant = collect($images)->groupBy('variant')->map(
            fn ($group) => $group->sortByDesc(fn ($img) => ($img['height'] ?? 0) * ($img['width'] ?? 0))->first()
        );
        $anyLowRes = $largestPerVariant->contains(fn ($img) => max($img['height'] ?? 0, $img['width'] ?? 0) < self::IMAGE_LOW_RES_MAX_EDGE_PX);
        if ($anyLowRes) {
            $defects[] = $this->defect('IMAGE_LOW_RES', 'IMAGE');
        }

        if ($snapshot->parent_asin !== null && $snapshot->main_image_hash !== null) {
            $siblingShares = CdqListingSnapshot::where('parent_asin', $snapshot->parent_asin)
                ->where('asin', '!=', $snapshot->asin)
                ->where('marketplace_id', $snapshot->marketplace_id)
                ->where('main_image_hash', $snapshot->main_image_hash)
                ->whereIn('id', function ($q) {
                    // latest snapshot per ASIN only
                    $q->selectRaw('MAX(id)')->from('cdq_listing_snapshot')->groupBy('asin');
                })
                ->exists();
            if ($siblingShares) {
                $defects[] = $this->defect('VARIANT_IMAGE_SHARED', 'IMAGE');
            }
        }

        return $defects;
    }

    private function checkAplus(CdqListingSnapshot $snapshot): array
    {
        $defects = [];
        $aplus = $snapshot->aplus_json ?? [];
        $live = array_filter($aplus, fn ($doc) => ($doc['status'] ?? null) === 'APPROVED');

        if (empty($live)) {
            $defects[] = $this->defect('APLUS_MISSING', 'APLUS');

            return $defects;
        }

        // getContentDocument() genuinely 400s on some real content this
        // account has (confirmed live in Phase 2 — "Unsupported content
        // module type"), leaving module_count/has_comparison_table/
        // has_video all null for that doc — "we don't know", not "it
        // fails this check". Only verified docs (module_count !== null)
        // feed these three sub-checks; if every live doc is unverifiable,
        // none of them fire rather than defaulting to "missing".
        $verified = array_filter($live, fn ($doc) => $doc['module_count'] !== null);
        if (empty($verified)) {
            return $defects;
        }

        $thinCount = count(array_filter($verified, fn ($doc) => $doc['module_count'] < self::APLUS_THIN_MODULE_COUNT));
        if ($thinCount > 0) {
            $defects[] = $this->defect('APLUS_THIN', 'APLUS', instances: $thinCount);
        }

        $hasComparisonTable = array_filter($verified, fn ($doc) => $doc['has_comparison_table'] ?? false);
        if (empty($hasComparisonTable)) {
            $defects[] = $this->defect('APLUS_NO_COMPARISON_MODULE', 'APLUS');
        }

        $hasVideo = array_filter($verified, fn ($doc) => $doc['has_video'] ?? false);
        if (empty($hasVideo)) {
            $defects[] = $this->defect('VIDEO_MISSING', 'APLUS');
        }

        return $defects;
    }

    /**
     * Each call here records ONE instance's true, uncapped points — a
     * check that fires 40 times produces 40 defect rows at their real
     * per-instance value, an honest record for the drill-down UI. The
     * instance_cap is a bound on the check code's TOTAL contribution to
     * the score, not on any single call — that aggregate cap is applied
     * once per check_code in rollUp(), not here. (Bug found live: applying
     * the cap per-call instead of in aggregate let e.g. 44 real
     * ATTR_RECOMMENDED_MISSING instances at 1.5pts each sum to 66 raw
     * points actually deducted, versus the intended cap of 9 — silently
     * driving attr_score to 0 for nearly every scored ASIN since the very
     * first Phase 3 run.)
     */
    private function defect(string $checkCode, string $dimension, ?string $attributePath = null, ?array $detail = null, int $instances = 1): array
    {
        $config = $this->checks->get($checkCode);
        if ($config === null) {
            return ['check_code' => $checkCode, 'dimension' => $dimension, 'points' => 0.0, 'severity' => 'MINOR', 'attribute_path' => $attributePath, 'detail' => $detail, 'skip' => true];
        }

        $points = (float) $config->points * ($config->per_instance ? $instances : 1);

        return [
            'check_code' => $checkCode,
            'dimension' => $dimension,
            'points' => $points,
            'severity' => match (true) {
                $points >= 10 => 'CRITICAL',
                $points >= 5 => 'ERROR',
                $points >= 2 => 'WARN',
                default => 'INFO',
            },
            'attribute_path' => $attributePath,
            'detail' => $detail,
            'skip' => false,
        ];
    }

    /** @return array{ATTR: float, TEXT: float, IMAGE: float, APLUS: float, CONSIST: float} */
    private function rollUp(array $defects): array
    {
        $weights = ['ATTR' => 30, 'TEXT' => 20, 'IMAGE' => 20, 'APLUS' => 20];
        $deductions = ['ATTR' => 0.0, 'TEXT' => 0.0, 'IMAGE' => 0.0, 'APLUS' => 0.0];

        // Aggregate cap per check_code — a check that fires many times
        // (e.g. many missing recommended attributes) deducts at most its
        // configured instance_cap in total, not once per firing.
        $byCheckCode = [];
        foreach ($defects as $defect) {
            if ($defect['skip']) {
                continue;
            }
            $byCheckCode[$defect['check_code']]['dimension'] = $defect['dimension'];
            $byCheckCode[$defect['check_code']]['points'] = ($byCheckCode[$defect['check_code']]['points'] ?? 0.0) + $defect['points'];
        }

        foreach ($byCheckCode as $checkCode => $group) {
            $config = $this->checks->get($checkCode);
            $capped = $config?->instance_cap !== null ? min($group['points'], (float) $config->instance_cap) : $group['points'];
            $deductions[$group['dimension']] += $capped;
        }

        $scores = [];
        foreach ($weights as $dimension => $weight) {
            $scores[$dimension] = round(max(0, $weight - $deductions[$dimension]), 2);
        }

        // CONSIST (LLM-scored, Phase 4) isn't implemented yet — scored as
        // full credit rather than 0, so a listing isn't penalized for a
        // dimension nothing can evaluate yet. Revisit once Phase 4 exists.
        $scores['CONSIST'] = 10.0;

        return $scores;
    }

    private function storeDefects(CdqListingSnapshot $snapshot, array $defects): void
    {
        foreach ($defects as $defect) {
            if ($defect['skip']) {
                continue;
            }
            CdqDefect::create([
                'snapshot_id' => $snapshot->id,
                'dimension' => $defect['dimension'],
                'check_code' => $defect['check_code'],
                'severity' => $defect['severity'],
                'points' => $defect['points'],
                'attribute_path' => $defect['attribute_path'],
                'detail_json' => $defect['detail'],
            ]);
        }
    }

    private function persist(CdqListingSnapshot $snapshot, array $defects, string $grade, ?float $total, ?array $scores = null): CdqScore
    {
        return CdqScore::updateOrCreate(
            ['snapshot_id' => $snapshot->id, 'check_config_version_id' => $this->version->id],
            [
                'attr_score' => $scores['ATTR'] ?? null,
                'text_score' => $scores['TEXT'] ?? null,
                'image_score' => $scores['IMAGE'] ?? null,
                'aplus_score' => $scores['APLUS'] ?? null,
                'consist_score' => $scores['CONSIST'] ?? null,
                'total_score' => $total,
                'grade' => $grade,
                'scored_at' => now(),
                // The provisional CONSIST credit above isn't a check
                // (found issue 13); cdq:consistency sets this when it runs.
                'consist_checked_at' => null,
            ]
        );
    }

    private function attributeValue(CdqListingSnapshot $snapshot, string $attributeName): ?string
    {
        return $snapshot->attributes_json[$attributeName][0]['value'] ?? null;
    }
}
