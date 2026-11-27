<?php

namespace Tests\Unit\Services\Cdq;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqDefect;
use App\Models\CdqListingSnapshot;
use App\Models\CdqLlmCache;
use App\Models\CdqScore;
use App\Services\Cdq\CdqConsistencyService;
use Database\Seeders\CdqCheckConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * No real Anthropic or SP-API call happens anywhere in this file — the
 * brief is explicit: "The LLM pass is mocked in all tests. No test may hit
 * the Anthropic API or SP-API."
 */
class CdqConsistencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CdqCheckConfigSeeder::class);
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.base_url' => 'https://api.anthropic.test']);
    }

    private function version(): CdqCheckConfigVersion
    {
        return CdqCheckConfigVersion::orderByDesc('effective_from')->first();
    }

    private function snapshotWithScore(array $overrides = []): CdqListingSnapshot
    {
        $snapshot = CdqListingSnapshot::create(array_merge([
            'asin' => 'B0TEST01',
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => []]]],
            'attributes_json' => ['item_name' => [['value' => 'A Product Title']]],
            'aplus_json' => [],
            'issues_json' => [],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ], $overrides));

        CdqScore::create([
            'snapshot_id' => $snapshot->id,
            'check_config_version_id' => $this->version()->id,
            'attr_score' => 30, 'text_score' => 20, 'image_score' => 20, 'aplus_score' => 20, 'consist_score' => 10,
            'total_score' => 100, 'grade' => 'A', 'scored_at' => now(),
        ]);

        return $snapshot;
    }

    private function anthropicResponse(array $conflicts): array
    {
        return ['content' => [['type' => 'text', 'text' => json_encode(['conflicts' => $conflicts])]]];
    }

    public function test_a_real_conflict_creates_a_consist_defect_and_updates_the_score(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response($this->anthropicResponse([
            ['check_code' => 'CONSIST_TITLE_VS_ATTR', 'fields' => ['item_name'], 'claim_a' => 'x', 'claim_b' => 'y', 'confidence' => 0.9],
        ]))]);

        $snapshot = $this->snapshotWithScore();
        $score = (new CdqConsistencyService($this->version()))->evaluate($snapshot);

        $this->assertEquals(6.0, (float) $score->consist_score); // 10 - 4pts
        $this->assertEquals(96.0, (float) $score->total_score);
        $this->assertDatabaseHas('cdq_defect', ['snapshot_id' => $snapshot->id, 'check_code' => 'CONSIST_TITLE_VS_ATTR', 'dimension' => 'CONSIST']);
    }

    /** Regression test for the same per-call-vs-aggregate cap bug found and fixed in CdqScoringService::rollUp(). */
    public function test_multiple_conflicts_of_the_same_check_code_are_capped_in_aggregate(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response($this->anthropicResponse([
            ['check_code' => 'CONSIST_TITLE_VS_ATTR', 'fields' => ['a'], 'claim_a' => 'x', 'claim_b' => 'y', 'confidence' => 0.9],
            ['check_code' => 'CONSIST_TITLE_VS_ATTR', 'fields' => ['b'], 'claim_a' => 'x', 'claim_b' => 'y', 'confidence' => 0.9],
            ['check_code' => 'CONSIST_TITLE_VS_ATTR', 'fields' => ['c'], 'claim_a' => 'x', 'claim_b' => 'y', 'confidence' => 0.9],
        ]))]);

        $snapshot = $this->snapshotWithScore();
        $score = (new CdqConsistencyService($this->version()))->evaluate($snapshot);

        // 3 conflicts * 4pts = 12 raw, but instance_cap is 8 -> consist_score must be 10-8=2, not 10-12(floored 0).
        $this->assertEquals(2.0, (float) $score->consist_score);
        $this->assertSame(3, CdqDefect::where('snapshot_id', $snapshot->id)->where('check_code', 'CONSIST_TITLE_VS_ATTR')->count());
    }

    public function test_a_low_confidence_conflict_is_discarded_not_deducted(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response($this->anthropicResponse([
            ['check_code' => 'CONSIST_TITLE_VS_ATTR', 'fields' => ['item_name'], 'claim_a' => 'x', 'claim_b' => 'y', 'confidence' => 0.4],
        ]))]);

        $snapshot = $this->snapshotWithScore();
        $score = (new CdqConsistencyService($this->version()))->evaluate($snapshot);

        $this->assertEquals(10.0, (float) $score->consist_score);
        $this->assertDatabaseMissing('cdq_defect', ['snapshot_id' => $snapshot->id, 'dimension' => 'CONSIST']);
    }

    public function test_a_malformed_response_yields_full_credit_not_a_deduction(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => 'not json at all']]])]);

        $snapshot = $this->snapshotWithScore();
        $score = (new CdqConsistencyService($this->version()))->evaluate($snapshot);

        $this->assertEquals(10.0, (float) $score->consist_score);
        $this->assertEquals(100.0, (float) $score->total_score);
    }

    public function test_second_evaluation_of_unchanged_content_hits_the_cache_not_the_api(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response($this->anthropicResponse([]))]);

        $snapshot = $this->snapshotWithScore();
        $service = new CdqConsistencyService($this->version());
        $service->evaluate($snapshot);
        $service->evaluate($snapshot);

        Http::assertSentCount(1);
        $this->assertSame(1, CdqLlmCache::count());
    }

    public function test_grade_u_snapshots_are_skipped_entirely_no_api_call(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response($this->anthropicResponse([]))]);

        $snapshot = $this->snapshotWithScore();
        CdqScore::where('snapshot_id', $snapshot->id)->update(['grade' => 'U', 'total_score' => null]);

        $score = (new CdqConsistencyService($this->version()))->evaluate($snapshot);

        Http::assertNothingSent();
        $this->assertSame('U', $score->grade);
    }

    public function test_throws_when_no_prior_score_exists_for_this_snapshot(): void
    {
        $snapshot = CdqListingSnapshot::create([
            'asin' => 'B0NOSCORE',
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => []]]],
            'attributes_json' => [],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        (new CdqConsistencyService($this->version()))->evaluate($snapshot);
    }

    public function test_missing_api_key_degrades_gracefully_no_deduction_no_crash(): void
    {
        config(['services.anthropic.api_key' => null]);
        Http::fake();

        $snapshot = $this->snapshotWithScore();
        $score = (new CdqConsistencyService($this->version()))->evaluate($snapshot);

        Http::assertNothingSent();
        $this->assertEquals(10.0, (float) $score->consist_score);
    }
}
