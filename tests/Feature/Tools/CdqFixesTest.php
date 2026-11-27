<?php

namespace Tests\Feature\Tools;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqListingSnapshot;
use App\Models\CdqScore;
use App\Models\Product;
use App\Models\ScheduledTask;
use App\Models\Tool;
use App\Models\User;
use Database\Seeders\CdqCheckConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CDQ fixes beyond the ported tests: found issues 13 and 14. Every value is
 * made up; no test reaches Anthropic or SP-API.
 */
class CdqFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CdqCheckConfigSeeder::class);
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.base_url' => 'https://api.anthropic.test']);
    }

    private function viewer(): User
    {
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo('tool.cdq-report');

        return $user;
    }

    private function scored(array $snapshot = [], array $score = []): CdqListingSnapshot
    {
        $row = CdqListingSnapshot::create($snapshot + [
            'asin' => 'B0MADEUP1',
            'sku' => 'MADE-UP-1',
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => []]]],
            'attributes_json' => ['item_name' => [['value' => 'Made-up Title']]],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ]);
        CdqScore::create($score + [
            'snapshot_id' => $row->id,
            'check_config_version_id' => CdqCheckConfigVersion::orderByDesc('effective_from')->value('id'),
            'attr_score' => 25, 'text_score' => 15, 'image_score' => 18, 'aplus_score' => 16, 'consist_score' => 10,
            'total_score' => 84, 'grade' => 'B', 'scored_at' => now(),
        ]);

        return $row;
    }

    public function test_the_tool_and_its_sunday_tasks_are_registered_at_the_old_schedules(): void
    {
        $this->assertSame('Amazon · Listings', Tool::where('slug', 'cdq-report')->sole()->group);
        foreach (['cdq:sync-schemas' => '0 4 * * 0', 'cdq:snapshot' => '15 4 * * 0', 'cdq:score' => '45 4 * * 0', 'cdq:consistency' => '15 5 * * 0'] as $command => $cron) {
            $this->assertSame($cron, ScheduledTask::where('command', $command)->sole()->cron_expression, $command);
        }
    }

    /** Issue 13: without a key, every listing silently got full consistency credit. */
    public function test_without_an_api_key_the_consistency_task_fails_and_records_nothing_as_checked(): void
    {
        config(['services.anthropic.api_key' => null]);
        Http::fake();
        $snapshot = $this->scored();

        $this->artisan('cdq:consistency')
            ->expectsOutputToContain('1 listing(s) not checked')
            ->assertFailed();

        $score = CdqScore::where('snapshot_id', $snapshot->id)->sole();
        $this->assertNull($score->consist_checked_at);
        $this->assertEquals(10.0, (float) $score->consist_score, 'the score itself is kept, as decided 2026-12-03');
    }

    /** Issue 13: a failed Anthropic call was also scored as "no conflicts". */
    public function test_a_failed_anthropic_call_is_not_counted_as_checked(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response(['error' => 'overloaded'], 529)]);
        $snapshot = $this->scored();

        $this->artisan('cdq:consistency')->assertFailed();

        $this->assertNull(CdqScore::where('snapshot_id', $snapshot->id)->sole()->consist_checked_at);
    }

    public function test_a_real_check_is_recorded_and_rescoring_clears_it(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"conflicts":[]}']]])]);
        $snapshot = $this->scored();

        $this->artisan('cdq:consistency')->assertSuccessful();
        $this->assertNotNull(CdqScore::where('snapshot_id', $snapshot->id)->sole()->consist_checked_at);

        $this->artisan('cdq:score', ['--snapshot' => $snapshot->id, '--rescore-all' => true])->assertSuccessful();
        $this->assertNull(CdqScore::where('snapshot_id', $snapshot->id)->sole()->consist_checked_at, 'a fresh score puts the provisional 10 back, so it is unchecked again');
    }

    /** Issue 13: the page showed an unchecked 10.0 as if it were a result. */
    public function test_the_page_says_when_consistency_was_not_checked(): void
    {
        $this->scored(['asin' => 'B0UNCHECKD']);
        $this->scored(['asin' => 'B0CHECKED1'], ['consist_score' => 6, 'consist_checked_at' => now()]);

        $this->actingAs($this->viewer())->get(route('tools.cdq-report.index'))
            ->assertOk()
            ->assertSeeInOrder(['B0UNCHECKD', 'Not checked'])
            ->assertSeeInOrder(['B0CHECKED1', '6.0']);

        $this->actingAs($this->viewer())->get(route('tools.cdq-report.show', 'B0UNCHECKD'))
            ->assertOk()
            ->assertSee('Not checked');
    }

    /** Issue 14: the CSV export did not quote ASIN or SKU. */
    public function test_the_csv_quotes_every_field(): void
    {
        $this->scored(['sku' => 'MADE,UP "2"']);
        Product::factory()->create(['asin' => 'B0MADEUP1', 'title' => 'Made-up Title']);

        $response = $this->actingAs($this->viewer())->get(route('tools.cdq-report.export-csv'));

        $lines = array_values(array_filter(explode("\n", $response->getContent())));
        $header = str_getcsv($lines[0], escape: '');
        $row = array_combine($header, str_getcsv($lines[1], escape: ''));
        $this->assertSame('B0MADEUP1', $row['ASIN']);
        $this->assertSame('MADE,UP "2"', $row['SKU']);
        $this->assertSame('Made-up Title', $row['Title']);
    }
}
