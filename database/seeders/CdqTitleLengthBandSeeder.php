<?php

namespace Database\Seeders;

use App\Models\CdqTitleLengthBand;
use Illuminate\Database\Seeder;

/**
 * My own starting sample, NOT an authoritative Amazon-published guideline
 * — run manually (`php artisan db:seed --class=CdqTitleLengthBandSeeder`),
 * never from a migration, same reasoning as every other CDQ seeder.
 *
 * Data-informed, not guessed in a vacuum: bands below are derived from
 * this catalog's own real title-length distribution per product type
 * (captured live, ~p10–p90 with some rounding headroom on each side —
 * see context.md for the exact percentiles pulled). That's a defensible
 * starting point (titles that are already typical for this catalog won't
 * false-flag), but it is NOT Amazon's actual published guidance, which I
 * have no access to — Doug should replace these with real numbers if
 * Amazon's category-specific style guide says something different.
 *
 * FOOD and PROTEIN_DRINK are deliberately NOT seeded — only 1 real ASIN
 * each in this catalog, nowhere near enough data to derive a band from
 * without a high risk of false-flagging every future FOOD/PROTEIN_DRINK
 * title. Left for Doug to set once there's more than one data point, or a
 * real style-guide number to use instead.
 */
class CdqTitleLengthBandSeeder extends Seeder
{
    private const MARKETPLACE = 'A2EUQ1WTGCTBG2';

    public function run(): void
    {
        $bands = [
            'HEALTH_PERSONAL_CARE' => [90, 160],
            'PROTEIN_SUPPLEMENT_POWDER' => [100, 150],
            'DRINK_FLAVORED' => [50, 200],
            'NUTRITIONAL_SUPPLEMENT' => [50, 130],
            'FLAVORED_DRINK_CONCENTRATE' => [50, 120],
        ];

        foreach ($bands as $productType => [$min, $max]) {
            CdqTitleLengthBand::updateOrCreate(
                ['marketplace_id' => self::MARKETPLACE, 'product_type' => $productType],
                ['min_length' => $min, 'max_length' => $max, 'notes' => 'Starting sample (Claude) — derived from this catalog\'s own real title lengths, not Amazon\'s published guidance. Not yet reviewed by Doug.']
            );
        }
    }
}
