<?php

namespace Tests\Feature\Tools;

use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockoutTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        // Changed in the rebuild (decision D7): the tool is renamed, with a new
        // slug, permission and route name. Nothing else in these tests changed.
        $this->tool = Tool::where('slug', 'biosteel-shopify-lost-sales')->firstOrFail();
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    private function seedEstimate(array $overrides = []): void
    {
        DB::table('lost_sales_estimates')->insert(array_merge([
            'variant_availability_interval_id' => 1,
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'sku' => 'BSHOODIE-M',
            'product_title' => 'BIOSTEEL HOODIE',
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
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_users_without_the_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.biosteel-shopify-lost-sales.index'))->assertForbidden();
    }

    public function test_index_shows_a_message_when_no_estimates_exist_yet(): void
    {
        $response = $this->actingAs($this->userWithAccess())->get(route('tools.biosteel-shopify-lost-sales.index'));

        $response->assertOk();
        $response->assertSee('No estimates yet');
    }

    public function test_index_shows_the_latest_batch_sorted_by_revenue_lost_descending(): void
    {
        // baseline_revenue_per_day drives the displayed (windowed) figure,
        // not the stored gross_revenue_lost column directly — see
        // StockoutController's per-row recomputation.
        $this->seedEstimate(['product_title' => 'Low Value', 'baseline_revenue_per_day' => 5, 'computed_at' => '2026-09-28 10:00:00']);
        $this->seedEstimate(['product_title' => 'High Value', 'baseline_revenue_per_day' => 50, 'computed_at' => '2026-09-28 10:00:00']);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.biosteel-shopify-lost-sales.index'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'High Value') < strpos($content, 'Low Value'));
    }

    public function test_index_only_shows_the_most_recent_computed_batch_not_older_runs(): void
    {
        $this->seedEstimate(['product_title' => 'Old Run', 'computed_at' => '2026-09-27 10:00:00']);
        $this->seedEstimate(['product_title' => 'New Run', 'computed_at' => '2026-09-28 10:00:00']);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.biosteel-shopify-lost-sales.index'));

        $response->assertOk();
        $response->assertSee('New Run');
        $response->assertDontSee('Old Run');
    }

    public function test_index_shows_confidence_badges_and_summary_totals(): void
    {
        $this->seedEstimate(['baseline_confidence' => 'normal', 'gross_revenue_lost' => 100]);
        $this->seedEstimate(['baseline_confidence' => 'thin_fallback', 'gross_revenue_lost' => 200, 'product_title' => 'Other Product']);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.biosteel-shopify-lost-sales.index'));

        $response->assertOk();
        $response->assertSee('$300'); // both default to baseline_revenue_per_day=50 x 3 window days = 150 each
        $response->assertSee('Thin fallback');
    }

    public function test_index_shows_the_sku_column(): void
    {
        $this->seedEstimate(['sku' => 'BSHOODIE-XL']);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.biosteel-shopify-lost-sales.index'));

        $response->assertOk();
        $response->assertSee('BSHOODIE-XL');
    }

    public function test_an_interval_entirely_outside_the_selected_range_is_excluded(): void
    {
        $this->seedEstimate(['product_title' => 'January Stockout', 'started_at' => '2026-01-01 00:00:00', 'ended_at' => '2026-01-03 23:59:59']);

        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.biosteel-shopify-lost-sales.index', ['start' => '2026-06-01', 'end' => '2026-06-30']));

        $response->assertOk();
        $response->assertDontSee('January Stockout');
    }

    public function test_a_long_running_interval_is_prorated_to_only_its_portion_inside_the_range(): void
    {
        // 10-day stockout, $10/day baseline -> $100 full total, but only
        // 3 of those days fall inside the selected range.
        $this->seedEstimate([
            'started_at' => '2026-01-01 00:00:00',
            'ended_at' => '2026-01-10 23:59:59',
            'baseline_revenue_per_day' => 10,
            'baseline_units_per_day' => 1,
        ]);

        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.biosteel-shopify-lost-sales.index', ['start' => '2026-01-08', 'end' => '2026-01-31']));

        $response->assertOk();
        // Jan 8, 9, 10 = 3 overlapping days x $10 = $30, not the full $100.
        $response->assertSee('$30');
        $response->assertDontSee('$100');
    }

    public function test_an_ongoing_interval_with_no_ended_at_is_included_in_a_recent_range(): void
    {
        $this->seedEstimate(['product_title' => 'Still Ongoing', 'started_at' => now()->subDays(5)->toDateTimeString(), 'ended_at' => null]);

        $response = $this->actingAs($this->userWithAccess())
            ->get(route('tools.biosteel-shopify-lost-sales.index', ['start' => now()->subDays(7)->toDateString(), 'end' => now()->toDateString()]));

        $response->assertOk();
        $response->assertSee('Still Ongoing');
    }

    public function test_no_range_params_shows_the_full_un_prorated_total(): void
    {
        $this->seedEstimate();

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.biosteel-shopify-lost-sales.index'));

        $response->assertOk();
        $response->assertSee('$150'); // full gross total, no window applied
    }
}
