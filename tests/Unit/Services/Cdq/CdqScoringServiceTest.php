<?php

namespace Tests\Unit\Services\Cdq;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqDefect;
use App\Models\CdqListingSnapshot;
use App\Models\CdqProductTypeSchema;
use App\Models\CdqScore;
use App\Models\CdqTitleLengthBand;
use App\Services\Cdq\CdqScoringService;
use Database\Seeders\CdqCheckConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Golden fixture tests for the deterministic scoring engine — each case is
 * built from shapes confirmed live against the real catalog during Phase 2
 * (attribute value structure, MAIN image variant/resolution ordering,
 * A+ module detail, real Listings Items issue severity/enforcement shape).
 * No SP-API or network calls happen anywhere in this file — the whole
 * point of a "pure and replayable" scoring engine is that it never needs
 * to.
 */
class CdqScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private const MARKETPLACE = 'A2EUQ1WTGCTBG2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CdqCheckConfigSeeder::class);

        CdqProductTypeSchema::create([
            'product_type' => 'DRINK_FLAVORED',
            'marketplace_id' => self::MARKETPLACE,
            'required_json' => ['brand', 'item_name'],
            'recommended_json' => ['flavor'],
            'raw_schema' => ['properties' => []],
            'fetched_at' => now(),
        ]);
    }

    private function service(): CdqScoringService
    {
        return new CdqScoringService(CdqCheckConfigVersion::orderByDesc('effective_from')->first());
    }

    /** @return array<string, mixed> */
    private function cleanAttributes(): array
    {
        return [
            'brand' => [['value' => 'Test Brand']],
            'flavor' => [['value' => 'Mixed Berry']],
            'item_name' => [['value' => str_repeat('A clean well formed product title ', 3)]], // ~105 chars, in-band
            'bullet_point' => array_fill(0, 5, ['value' => str_repeat('A solid, sufficiently detailed bullet point. ', 2)]),
            'rtip_product_description' => [['value' => 'A full description.']],
            'generic_keyword' => [['value' => 'keyword one two three']],
        ];
    }

    /** @return array<int, array{variant:string, link:string, height:int, width:int}> */
    private function cleanImages(): array
    {
        $variants = ['MAIN', 'PT01', 'PT02', 'PT03', 'PT04', 'PT05'];

        return array_map(fn ($v) => ['variant' => $v, 'link' => "https://img.test/{$v}.jpg", 'height' => 1600, 'width' => 1600], $variants);
    }

    private function cleanAplus(): array
    {
        return [['name' => 'Doc', 'status' => 'APPROVED', 'module_count' => 5, 'has_comparison_table' => true, 'has_video' => true]];
    }

    private function makeSnapshot(array $overrides = []): CdqListingSnapshot
    {
        return CdqListingSnapshot::create(array_merge([
            'asin' => 'B0CLEAN01',
            'sku' => 'SKU-CLEAN',
            'marketplace_id' => self::MARKETPLACE,
            'product_type' => 'DRINK_FLAVORED',
            'parent_asin' => null,
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => $this->cleanImages()]]],
            'attributes_json' => $this->cleanAttributes(),
            'main_image_hash' => hash('sha256', 'main-bytes'),
            'aplus_json' => $this->cleanAplus(),
            'issues_json' => [],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ], $overrides));
    }

    public function test_a_fully_clean_listing_scores_a_perfect_hundred_grade_a(): void
    {
        $score = $this->service()->score($this->makeSnapshot());

        $this->assertSame('A', $score->grade);
        $this->assertEquals(100.0, (float) $score->total_score);
        $this->assertEquals(30.0, (float) $score->attr_score);
        $this->assertEquals(20.0, (float) $score->text_score);
        $this->assertEquals(20.0, (float) $score->image_score);
        $this->assertEquals(20.0, (float) $score->aplus_score);
    }

    public function test_title_length_out_of_band_only_fires_when_a_curated_band_exists(): void
    {
        // No cdq_title_length_band row for DRINK_FLAVORED — check must not
        // fire at all (no fallback to a guessed global band).
        $score = $this->service()->score($this->makeSnapshot());
        $this->assertDatabaseMissing('cdq_defect', ['check_code' => 'TITLE_LENGTH_OUT_OF_BAND']);
        $this->assertEquals(20.0, (float) $score->text_score);

        CdqTitleLengthBand::create([
            'marketplace_id' => self::MARKETPLACE,
            'product_type' => 'DRINK_FLAVORED',
            'min_length' => 20,
            'max_length' => 50, // the clean fixture's title is well over 50 chars
        ]);

        // Changed in the rebuild: the made-up ASIN was 11 characters, longer than
        // the 10-character asin column, which only SQLite let through.
        $score = $this->service()->score($this->makeSnapshot(['asin' => 'B0TITLEBN1']));
        $this->assertDatabaseHas('cdq_defect', ['snapshot_id' => $score->snapshot_id, 'check_code' => 'TITLE_LENGTH_OUT_OF_BAND']);
        $this->assertEquals(15.0, (float) $score->text_score);
    }

    public function test_missing_required_attribute_deducts_from_attr_dimension_only(): void
    {
        $attributes = $this->cleanAttributes();
        unset($attributes['brand']);

        $score = $this->service()->score($this->makeSnapshot(['attributes_json' => $attributes]));

        // brand missing (required, 6pts) + item_name still present (Grade D
        // checks title separately, unaffected here).
        $this->assertEquals(24.0, (float) $score->attr_score);
        $this->assertEquals(20.0, (float) $score->text_score);
        $this->assertSame('A', $score->grade); // 24+20+20+20+10 = 94
    }

    public function test_attribute_poor_listing_stacks_multiple_dimension_deductions(): void
    {
        $attributes = $this->cleanAttributes();
        unset($attributes['brand'], $attributes['flavor']);
        $attributes['bullet_point'] = array_fill(0, 2, ['value' => 'short']);

        $score = $this->service()->score($this->makeSnapshot(['attributes_json' => $attributes]));

        // ATTR: brand missing (6) + flavor missing (1.5) = 7.5 -> 30-7.5=22.5
        $this->assertEquals(22.5, (float) $score->attr_score);
        // TEXT: bullets_under_five (3 missing * 2pts = 6) + 2 thin bullets (1pt each = 2) = 8 -> 20-8=12
        $this->assertEquals(12.0, (float) $score->text_score);
    }

    /**
     * Regression test for a real bug found live: instance_cap must bound a
     * check code's TOTAL deduction across every time it fires, not just
     * each individual firing on its own (which trivially passes its own
     * cap and let deductions add up unbounded — silently drove attr_score
     * to 0 for nearly every real ASIN with a matched schema, from the very
     * first Phase 3 run).
     */
    public function test_a_check_firing_many_times_is_capped_in_aggregate_not_per_instance(): void
    {
        CdqProductTypeSchema::where('product_type', 'DRINK_FLAVORED')->update([
            // 10 recommended attributes, all missing below — at 1.5pts
            // each that's 15 raw, but instance_cap is 9: attr_score must
            // reflect -9, not -15.
            'recommended_json' => ['flavor', 'r1', 'r2', 'r3', 'r4', 'r5', 'r6', 'r7', 'r8', 'r9'],
        ]);

        $score = $this->service()->score($this->makeSnapshot());

        $this->assertEquals(21.0, (float) $score->attr_score); // 30 - 9 (capped), not 30 - 13.5
        // 'flavor' is present in the clean fixture — only the other 9 are actually missing.
        $this->assertSame(9, CdqDefect::where('snapshot_id', $score->snapshot_id)->where('check_code', 'ATTR_RECOMMENDED_MISSING')->count());
    }

    public function test_a_real_content_change_produces_the_same_score_when_content_is_identical(): void
    {
        $snapshotA = $this->makeSnapshot();
        $snapshotB = $this->makeSnapshot(['asin' => 'B0CLEAN02']);

        $scoreA = $this->service()->score($snapshotA);
        $scoreB = $this->service()->score($snapshotB);

        $this->assertEquals($scoreA->total_score, $scoreB->total_score);
        $this->assertSame($scoreA->grade, $scoreB->grade);
    }

    public function test_shared_variant_main_image_flags_variant_image_shared(): void
    {
        $sharedHash = hash('sha256', 'identical-bytes');
        $sibling = $this->makeSnapshot(['asin' => 'B0SIBLING', 'parent_asin' => 'B0PARENT', 'main_image_hash' => $sharedHash]);
        $target = $this->makeSnapshot(['asin' => 'B0TARGET', 'parent_asin' => 'B0PARENT', 'main_image_hash' => $sharedHash]);

        $score = $this->service()->score($target);

        $this->assertLessThan(20.0, (float) $score->image_score);
        $this->assertDatabaseHas('cdq_defect', ['snapshot_id' => $target->id, 'check_code' => 'VARIANT_IMAGE_SHARED']);
    }

    public function test_distinct_variant_images_do_not_flag_variant_image_shared(): void
    {
        $sibling = $this->makeSnapshot(['asin' => 'B0SIBLING2', 'parent_asin' => 'B0PARENT2', 'main_image_hash' => hash('sha256', 'sibling-bytes')]);
        $target = $this->makeSnapshot(['asin' => 'B0TARGET2', 'parent_asin' => 'B0PARENT2', 'main_image_hash' => hash('sha256', 'target-bytes')]);

        $score = $this->service()->score($target);

        $this->assertEquals(20.0, (float) $score->image_score);
    }

    /**
     * Real observed shape (confirmed live): severity ERROR + a non-empty
     * enforcements.actions[] + exemption.status !== EXEMPT is what an
     * actual open policy violation looks like — a bare WARNING is not.
     */
    public function test_an_open_policy_violation_scores_grade_u_with_null_total(): void
    {
        $issues = [[
            'code' => '90225',
            'severity' => 'ERROR',
            'enforcements' => ['actions' => [['action' => 'ATTRIBUTE_SUPPRESSED']], 'exemption' => ['status' => 'NOT_EXEMPT']],
        ]];

        $score = $this->service()->score($this->makeSnapshot(['issues_json' => $issues]));

        $this->assertSame('U', $score->grade);
        $this->assertNull($score->total_score);
    }

    public function test_a_warning_only_issue_does_not_trigger_grade_u(): void
    {
        $issues = [[
            'code' => '8029',
            'severity' => 'WARNING',
            'enforcements' => ['actions' => [['action' => 'ATTRIBUTE_SUPPRESSED']], 'exemption' => ['status' => 'NOT_EXEMPT']],
        ]];

        $score = $this->service()->score($this->makeSnapshot(['issues_json' => $issues]));

        $this->assertNotSame('U', $score->grade);
    }

    public function test_missing_main_image_forces_grade_d_zero(): void
    {
        $images = array_values(array_filter($this->cleanImages(), fn ($img) => $img['variant'] !== 'MAIN'));

        $score = $this->service()->score($this->makeSnapshot(['catalog_json' => ['images' => [['images' => $images]]]]));

        $this->assertSame('D', $score->grade);
        $this->assertEquals(0.0, (float) $score->total_score);
    }

    public function test_missing_title_forces_grade_d_zero(): void
    {
        $attributes = $this->cleanAttributes();
        unset($attributes['item_name']);

        $score = $this->service()->score($this->makeSnapshot(['attributes_json' => $attributes]));

        $this->assertSame('D', $score->grade);
        $this->assertEquals(0.0, (float) $score->total_score);
    }

    public function test_rescoring_the_same_snapshot_and_config_is_idempotent(): void
    {
        $snapshot = $this->makeSnapshot();
        $service = $this->service();

        $first = $service->score($snapshot);
        $second = $service->score($snapshot);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CdqScore::where('snapshot_id', $snapshot->id)->count());
    }
}
