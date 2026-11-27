<?php

namespace Database\Seeders;

use App\Models\CdqCheckConfig;
use App\Models\CdqCheckConfigVersion;
use Illuminate\Database\Seeder;

/**
 * One-time seed of the initial CDQ check-config revision — run manually
 * (`php artisan db:seed --class=CdqCheckConfigSeeder`), never from a
 * migration: this table is versioned/insert-only, and a migration's
 * inserted rows persist across the whole test suite run under
 * RefreshDatabase, which would corrupt any test expecting to control its
 * own "current" config revision (this is exactly what happened with the
 * PO Forecasting horizon migration earlier — see context.md).
 *
 * Points/caps below are transcribed from the build brief's section 4
 * tables. ATTR_IMPLAUSIBLE and NO_INFOGRAPHIC are seeded disabled — no
 * plausibility-rule table or infographic-detection heuristic exists yet,
 * matching the precedent the brief itself sets for shipping a check
 * definition ahead of its detection logic. TITLE_LENGTH_OUT_OF_BAND is
 * data-driven off cdq_title_length_band (curated, currently empty) rather
 * than a hardcoded band, so it's safe to leave enabled — it simply never
 * fires until that table has rows.
 */
class CdqCheckConfigSeeder extends Seeder
{
    public function run(): void
    {
        $version = CdqCheckConfigVersion::create([
            'effective_from' => now(),
            'reason' => 'Initial CDQ check-config revision (Phase 3 build)',
            'changed_by' => null,
        ]);

        $checks = [
            // ATTR
            ['check_code' => 'ATTR_REQUIRED_MISSING', 'dimension' => 'ATTR', 'points' => 6, 'per_instance' => true, 'instance_cap' => 18],
            ['check_code' => 'ATTR_RECOMMENDED_MISSING', 'dimension' => 'ATTR', 'points' => 1.5, 'per_instance' => true, 'instance_cap' => 9],
            ['check_code' => 'ATTR_SCHEMA_INVALID', 'dimension' => 'ATTR', 'points' => 3, 'per_instance' => true, 'instance_cap' => 12],
            ['check_code' => 'ATTR_IMPLAUSIBLE', 'dimension' => 'ATTR', 'points' => 3, 'per_instance' => true, 'instance_cap' => 6, 'enabled' => false, 'notes' => 'No plausibility-rule table yet — seeded disabled, matching the NO_INFOGRAPHIC precedent.'],

            // TEXT
            ['check_code' => 'TITLE_LENGTH_OUT_OF_BAND', 'dimension' => 'TEXT', 'points' => 5, 'per_instance' => false],
            ['check_code' => 'TITLE_PROMO_LANGUAGE', 'dimension' => 'TEXT', 'points' => 4, 'per_instance' => false],
            ['check_code' => 'BULLETS_UNDER_FIVE', 'dimension' => 'TEXT', 'points' => 2, 'per_instance' => true, 'instance_cap' => 8],
            ['check_code' => 'BULLET_THIN', 'dimension' => 'TEXT', 'points' => 1, 'per_instance' => true, 'instance_cap' => 3],
            ['check_code' => 'DESCRIPTION_MISSING', 'dimension' => 'TEXT', 'points' => 5, 'per_instance' => false],
            ['check_code' => 'KEYWORDS_MISSING', 'dimension' => 'TEXT', 'points' => 4, 'per_instance' => false],

            // IMAGE
            ['check_code' => 'MAIN_IMAGE_MISSING', 'dimension' => 'IMAGE', 'points' => 20, 'per_instance' => false],
            ['check_code' => 'IMAGE_COUNT_UNDER_SIX', 'dimension' => 'IMAGE', 'points' => 2, 'per_instance' => true, 'instance_cap' => 8],
            ['check_code' => 'IMAGE_LOW_RES', 'dimension' => 'IMAGE', 'points' => 3, 'per_instance' => false],
            ['check_code' => 'VARIANT_IMAGE_SHARED', 'dimension' => 'IMAGE', 'points' => 6, 'per_instance' => false],
            ['check_code' => 'NO_INFOGRAPHIC', 'dimension' => 'IMAGE', 'points' => 3, 'per_instance' => false, 'enabled' => false, 'notes' => 'No infographic-detection heuristic yet — seeded disabled per the brief.'],

            // APLUS
            ['check_code' => 'APLUS_MISSING', 'dimension' => 'APLUS', 'points' => 14, 'per_instance' => false],
            ['check_code' => 'APLUS_THIN', 'dimension' => 'APLUS', 'points' => 2, 'per_instance' => true, 'instance_cap' => 4],
            ['check_code' => 'APLUS_NO_COMPARISON_MODULE', 'dimension' => 'APLUS', 'points' => 2, 'per_instance' => false],
            ['check_code' => 'VIDEO_MISSING', 'dimension' => 'APLUS', 'points' => 4, 'per_instance' => false],

            // CONSIST (LLM pass, Phase 4)
            ['check_code' => 'CONSIST_TITLE_VS_ATTR', 'dimension' => 'CONSIST', 'points' => 4, 'per_instance' => true, 'instance_cap' => 8],
            ['check_code' => 'CONSIST_IMAGE_VS_TEXT', 'dimension' => 'CONSIST', 'points' => 3, 'per_instance' => true, 'instance_cap' => 6],
            ['check_code' => 'CONSIST_VARIATION_THEME', 'dimension' => 'CONSIST', 'points' => 3, 'per_instance' => false],
        ];

        foreach ($checks as $check) {
            CdqCheckConfig::create([
                'version_id' => $version->id,
                'enabled' => true,
                ...$check,
            ]);
        }
    }
}
