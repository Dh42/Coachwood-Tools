<?php

namespace Tests\Feature\Console;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqListingSnapshot;
use App\Models\CdqScore;
use Database\Seeders\CdqCheckConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CdqConsistencyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CdqCheckConfigSeeder::class);
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.base_url' => 'https://api.anthropic.test']);
        Http::fake(['api.anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"conflicts":[]}']]])]);
    }

    private function snapshotWithScore(array $overrides = []): CdqListingSnapshot
    {
        $version = CdqCheckConfigVersion::orderByDesc('effective_from')->first();
        $snapshot = CdqListingSnapshot::create(array_merge([
            'asin' => 'B0TEST01',
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => []]]],
            'attributes_json' => [],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ], $overrides));

        CdqScore::create([
            'snapshot_id' => $snapshot->id,
            'check_config_version_id' => $version->id,
            'attr_score' => 30, 'text_score' => 20, 'image_score' => 20, 'aplus_score' => 20, 'consist_score' => 10,
            'total_score' => 100, 'grade' => 'A', 'scored_at' => now(),
        ]);

        return $snapshot;
    }

    public function test_evaluates_every_scored_snapshot_by_default(): void
    {
        $this->snapshotWithScore();
        $this->snapshotWithScore(['asin' => 'B0TEST02']);

        $this->artisan('cdq:consistency')->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_asin_option_scopes_to_one_snapshot(): void
    {
        $this->snapshotWithScore();
        $this->snapshotWithScore(['asin' => 'B0OTHER']);

        $this->artisan('cdq:consistency', ['--asin' => 'B0TEST01'])->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_fails_cleanly_when_no_snapshot_has_been_scored_yet(): void
    {
        CdqListingSnapshot::create([
            'asin' => 'B0UNSCORED',
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => []]]],
            'attributes_json' => [],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ]);

        $this->artisan('cdq:consistency')->assertFailed();
        Http::assertNothingSent();
    }
}
