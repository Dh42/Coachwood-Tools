<?php

namespace App\Services\AmazonListings;

/**
 * Ports the legacy tool's buildNutritionItem() exactly — confirmed
 * byte-for-byte identical between the Vendor and Seller Central files
 * (including the hardcoded 'en_CA' language tag on both, which is
 * preserved here deliberately rather than "fixed" to en_US for the seller
 * channel, since that's the real, currently-live behavior, not a bug this
 * migration was asked to change).
 */
class NutritionFormBuilder
{
    public const GROUPS = [
        'energy' => 'Calories',
        'fat' => 'Fat',
        'carbohydrate' => 'Carbohydrate',
        'protein' => 'Protein',
    ];

    /**
     * @param  array  $posted  the raw nutrition[...] POST array
     * @param  array<string, mixed>  $nutritionSchemaProps  {group => true} for every group this product type's schema actually has
     */
    public static function build(array $posted, array $nutritionSchemaProps, string $marketplaceId): array
    {
        $item = ['marketplace_id' => $marketplaceId];

        foreach (['serving_quantity', 'serving_unit'] as $f) {
            $v = trim((string) ($posted[$f] ?? ''));
            if ($v !== '') {
                $item[$f] = is_numeric($v) ? (float) $v : $v;
            }
        }

        $servingDesc = trim((string) ($posted['serving_description']['value'] ?? ''));
        if ($servingDesc !== '') {
            $item['serving_description'] = [['value' => $servingDesc, 'language_tag' => 'en_CA']];
        }

        // energy/fat/carbohydrate/protein are each schema'd as a 1-element
        // array wrapping the group object, not a bare object — Amazon
        // rejects the whole submission with a generic "not in the correct
        // format" error if this wrapping is missing. Confirmed real via a
        // VALIDATION_PREVIEW-only dry run before this was ever live.
        foreach (array_keys(self::GROUPS) as $group) {
            if (! isset($nutritionSchemaProps[$group])) {
                continue;
            }
            $groupItem = [];
            foreach ($posted[$group] ?? [] as $k => $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $groupItem[$k] = is_numeric($v) ? (float) $v : $v;
                }
            }
            if (! empty($groupItem)) {
                $item[$group] = [$groupItem];
            }
        }

        $vmRows = [];
        foreach ($posted['vitamins_and_minerals'] ?? [] as $row) {
            $nutrient = trim((string) ($row['nutrient'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($nutrient === '' && $value === '') {
                continue;
            }
            $vmItem = [];
            if ($nutrient !== '') {
                $vmItem['nutrient'] = $nutrient;
            }
            if ($value !== '') {
                $vmItem['value'] = is_numeric($value) ? (float) $value : $value;
            }
            foreach (['unit', 'string_value'] as $extra) {
                $ev = trim((string) ($row[$extra] ?? ''));
                if ($ev !== '') {
                    $vmItem[$extra] = $ev;
                }
            }
            if (! empty($vmItem)) {
                $vmRows[] = $vmItem;
            }
        }
        if (! empty($vmRows)) {
            $item['vitamins_and_minerals'] = $vmRows;
        }

        return $item;
    }
}
