<?php

namespace Tests\Feature\Tools;

use App\Models\ScheduledTask;
use App\Models\Tool;
use App\Models\User;
use App\Models\WalmartUsCatalogScore;
use App\Models\WalmartUsListingAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The rebuilt Walmart US Listing Auditor pages and registration, beyond the ported tests. */
class WalmartUsPageTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo('tool.walmart-us-listing-auditor');

        return $user;
    }

    public function test_the_tool_and_its_nightly_task_are_registered(): void
    {
        $this->assertSame('Walmart', Tool::where('slug', 'walmart-us-listing-auditor')->sole()->group);
        $this->assertSame('0 6 * * *', ScheduledTask::where('command', 'walmart-us:listing-audit')->sole()->cron_expression);
    }

    public function test_scores_carry_their_band_in_words_and_sort_by_the_number(): void
    {
        foreach (['SKU-GOOD' => 91, 'SKU-FAIR' => 64, 'SKU-POOR' => 31] as $sku => $score) {
            WalmartUsListingAudit::create(['sku' => $sku, 'as_of_date' => '2026-10-05', 'run_at' => '2026-10-05 06:10:00', 'listing_quality' => $score]);
        }

        $this->actingAs($this->viewer())->get(route('tools.walmart-us-listing-auditor.index'))
            ->assertSee('91.0 · Good')
            ->assertSee('64.0 · Fair')
            ->assertSee('31.0 · Poor')
            ->assertSee('data-value="91"', false);
    }

    public function test_the_catalog_rollup_shows_its_scores_and_how_fresh_they_are(): void
    {
        WalmartUsCatalogScore::create(['run_at' => now()->subHours(3), 'listing_quality' => 70.5, 'item_count' => 27]);

        $this->actingAs($this->viewer())->get(route('tools.walmart-us-listing-auditor.index'))
            ->assertSee('70.5')
            ->assertSee('27')
            ->assertSee('Updated 3 hours ago');
    }
}
