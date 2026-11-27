<?php

namespace Tests\Feature\Tools;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BioSteel Shopify Lost Sales: fixes and changes beyond the ported tests.
 * Every value is made up.
 */
class LostSalesFixesTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo('tool.biosteel-shopify-lost-sales');

        return $user;
    }

    private function seedEstimate(array $overrides = []): void
    {
        DB::table('lost_sales_estimates')->insert($overrides + [
            'variant_availability_interval_id' => 1,
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'sku' => 'MADE-UP-1',
            'product_title' => 'Made-up Product',
            'started_at' => '2026-01-10 00:00:00',
            'ended_at' => '2026-01-12 23:59:59',
            'days_out_of_stock' => 3,
            'baseline_units_per_day' => 5,
            'baseline_revenue_per_day' => 50,
            'baseline_window_days_used' => 3,
            'baseline_confidence' => 'normal',
            'gross_units_lost' => 15,
            'gross_revenue_lost' => 150,
            'model_version' => 'v1-trailing-baseline',
            'computed_at' => '2026-09-28 10:00:00',
        ]);
    }

    /** Issue 20: a malformed date in the filter returned a 500. */
    public function test_a_malformed_date_is_ignored_instead_of_a_500(): void
    {
        $this->seedEstimate();

        $this->actingAs($this->viewer())
            ->get(route('tools.biosteel-shopify-lost-sales.index', ['start' => 'not-a-date', 'end' => '2026-13-45']))
            ->assertOk()
            ->assertViewHas('rangeStart', null)
            ->assertViewHas('rangeEnd', null)
            ->assertSee('$150');
    }

    public function test_one_valid_date_is_kept_when_the_other_is_malformed(): void
    {
        $this->seedEstimate();

        $this->actingAs($this->viewer())
            ->get(route('tools.biosteel-shopify-lost-sales.index', ['start' => '2026-01-11', 'end' => 'garbage']))
            ->assertOk()
            ->assertViewHas('rangeStart', '2026-01-11')
            ->assertViewHas('rangeEnd', null)
            ->assertSee('$100');
    }

    public function test_the_old_url_redirects_and_keeps_the_range(): void
    {
        $this->actingAs($this->viewer())
            ->get('/tools/stockout?start=2026-01-01&end=2026-01-31')
            ->assertStatus(301)
            ->assertRedirect(route('tools.biosteel-shopify-lost-sales.index', ['start' => '2026-01-01', 'end' => '2026-01-31']));
    }

    public function test_confidence_is_given_in_words_and_the_page_has_its_tutorial(): void
    {
        $this->seedEstimate(['baseline_confidence' => 'insufficient_data']);
        $this->seedEstimate(['baseline_confidence' => 'thin_fallback', 'product_title' => 'Another Made-up Product', 'substitution_note' => 'Made-up PET note']);

        $this->actingAs($this->viewer())->get(route('tools.biosteel-shopify-lost-sales.index'))
            ->assertOk()
            ->assertSee('Insufficient data')
            ->assertSee('Thin fallback')
            ->assertSee('PET cover')
            ->assertSee('Made-up PET note')
            ->assertSee('data-tour="stockout-tour-btn-steps"', false);
    }

    public function test_registering_the_webhook_is_refused_while_external_writes_are_off(): void
    {
        config([
            'tools.external_writes' => false,
            'stockout.shopify.store' => 'shopify-test.myshopify.com',
            'stockout.shopify.access_token' => 'test-token',
            'stockout.webhook_secret' => 'test-secret',
        ]);
        Http::fake(['shopify-test.myshopify.com/*' => Http::response(['data' => ['webhookSubscriptions' => ['edges' => []]]])]);

        $this->artisan('stockout:register-webhook')
            ->expectsOutputToContain('External writes are disabled')
            ->assertFailed();

        Http::assertSentCount(1); // the read-only list, never the create
    }
}
