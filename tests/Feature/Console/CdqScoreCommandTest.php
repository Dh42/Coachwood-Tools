<?php

namespace Tests\Feature\Console;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqListingSnapshot;
use App\Models\CdqScore;
use Database\Seeders\CdqCheckConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CdqScoreCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CdqCheckConfigSeeder::class);
    }

    private function snapshot(array $overrides = []): CdqListingSnapshot
    {
        return CdqListingSnapshot::create(array_merge([
            'asin' => 'B0TEST01',
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'product_type' => 'UNKNOWN_TYPE', // no schema row on purpose — ATTR checks just skip
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => [['variant' => 'MAIN', 'link' => 'x', 'height' => 1500, 'width' => 1500]]]]],
            'attributes_json' => ['item_name' => [['value' => str_repeat('A well formed title text ', 4)]]],
            'aplus_json' => [],
            'issues_json' => [],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ], $overrides));
    }

    public function test_scores_the_latest_snapshot_per_asin(): void
    {
        $old = $this->snapshot(['captured_at' => now()->subDay()]);
        $latest = $this->snapshot(['content_hash' => hash('sha256', 'new')]);

        $this->artisan('cdq:score')->assertSuccessful();

        $this->assertDatabaseHas('cdq_score', ['snapshot_id' => $latest->id]);
        $this->assertDatabaseMissing('cdq_score', ['snapshot_id' => $old->id]);
    }

    public function test_repeat_run_skips_already_scored_snapshots(): void
    {
        $this->snapshot();

        $this->artisan('cdq:score')->assertSuccessful();
        $this->artisan('cdq:score')->assertSuccessful();

        $this->assertSame(1, CdqScore::count());
    }

    public function test_rescore_all_forces_recomputation(): void
    {
        $snapshot = $this->snapshot();

        $this->artisan('cdq:score')->assertSuccessful();
        $this->artisan('cdq:score', ['--rescore-all' => true])->assertSuccessful();

        // updateOrCreate keeps it to one row per (snapshot, version) even
        // when forced — the point of --rescore-all is recomputing the
        // values, not creating duplicates.
        $this->assertSame(1, CdqScore::where('snapshot_id', $snapshot->id)->count());
    }

    public function test_snapshot_option_scores_a_specific_row_directly(): void
    {
        $target = $this->snapshot();
        $other = $this->snapshot(['asin' => 'B0OTHER', 'content_hash' => hash('sha256', 'other')]);

        $this->artisan('cdq:score', ['--snapshot' => $target->id])->assertSuccessful();

        $this->assertSame(1, CdqScore::count());
        $this->assertDatabaseHas('cdq_score', ['snapshot_id' => $target->id]);
    }

    public function test_as_of_uses_the_check_config_revision_effective_at_that_date(): void
    {
        $snapshot = $this->snapshot(['captured_at' => now()->subDays(25)]);

        $oldVersion = CdqCheckConfigVersion::create(['effective_from' => now()->subDays(30), 'reason' => 'old']);

        $this->artisan('cdq:score', ['--as-of' => now()->subDays(20)->toDateString()])->assertSuccessful();

        $this->assertDatabaseHas('cdq_score', ['snapshot_id' => $snapshot->id, 'check_config_version_id' => $oldVersion->id]);
    }

    public function test_fails_cleanly_when_no_check_config_version_exists(): void
    {
        CdqCheckConfigVersion::query()->delete();
        $this->snapshot();

        $this->artisan('cdq:score')->assertFailed();
    }
}
