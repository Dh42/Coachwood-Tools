<?php

namespace Tests\Feature\Tools;

use App\Models\Tool;
use App\Models\User;
use App\Services\AspTracker\AspReportService;
use App\Services\AspTracker\ListPriceResolver;
use App\Services\AspTracker\OrderLineMapper;
use App\Services\AspTracker\SalesLineWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\AspOrderFixtures;
use Tests\TestCase;

class AspTrackerTest extends TestCase
{
    use AspOrderFixtures;
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $this->tool = Tool::where('slug', 'asp-tracker')->firstOrFail();
        $this->travelTo('2026-10-20 12:00:00');
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    private function seedSales(): void
    {
        $mapper = new OrderLineMapper('CP');
        $orders = [
            // Week of Mon Oct 5: one full-price unit, two units with $20 of Rivo.
            self::order(1, [self::lineItem(11, ['originalUnitPriceSet' => self::money(80.00)])], ['createdAt' => '2026-10-06T16:00:00Z']),
            self::order(2, [self::lineItem(21, ['quantity' => 2, 'originalUnitPriceSet' => self::money(80.00), 'discountAllocations' => [
                self::allocation(20.00, 'ACROSS', 'DiscountCodeApplication', 'CAP-aaa111bbb222'),
            ]])], ['createdAt' => '2026-10-07T16:00:00Z']),
            // Week of Mon Oct 12: a subscription unit at $56 (list $80 from one-time sales), one refunded unit.
            self::order(3, [self::lineItem(31, ['sellingPlan' => ['name' => 'Delivery every 30 days'], 'originalUnitPriceSet' => self::money(56.00)])], ['createdAt' => '2026-10-13T16:00:00Z']),
            self::order(4, [self::lineItem(41, ['quantity' => 2, 'originalUnitPriceSet' => self::money(80.00)])], [
                'createdAt' => '2026-10-14T16:00:00Z',
                'refunds' => [self::refund('1', [[41, 1, 80.00]])],
            ]),
            // Excluded by default: free gift, cancelled order, draft order.
            self::order(5, [self::lineItem(51, ['discountAllocations' => [self::allocation(79.99, 'EACH', 'AutomaticDiscountApplication', 'Free shaker')]])], ['createdAt' => '2026-10-14T16:00:00Z']),
            self::order(6, [self::lineItem(61)], ['createdAt' => '2026-10-14T16:00:00Z', 'cancelledAt' => '2026-10-14T18:00:00Z']),
            self::order(7, [self::lineItem(71, ['originalUnitPriceSet' => self::money(40.00)])], ['createdAt' => '2026-10-14T16:00:00Z', 'sourceName' => 'shopify_draft_order', 'app' => ['name' => 'Draft Orders']]),
        ];

        (new SalesLineWriter)->write(array_map(fn ($o) => $mapper->map($o), $orders));
        (new ListPriceResolver)->resolve('CP');
    }

    private function filters(array $input = []): array
    {
        return AspReportService::normalizeFilters($input + ['from' => '2026-10-01', 'to' => '2026-10-20'], 'CP');
    }

    public function test_summary_metrics(): void
    {
        $this->seedSales();

        $s = app(AspReportService::class)->summary($this->filters());

        // Units: 1 + 2 + 1 + (2 − 1 refunded) = 5. Revenue: 80 + 140 + 56 + (160 − 80) = 356.
        $this->assertSame(5, $s['net_qty']);
        $this->assertEqualsWithDelta(356.00, $s['net_revenue'], 0.001);
        $this->assertEqualsWithDelta(71.20, $s['asp'], 0.001);
        // List: 6 sold units × $80.
        $this->assertEqualsWithDelta(80.00, $s['list_asp'], 0.001);
        $this->assertEqualsWithDelta(1 - 71.20 / 80, $s['discount_depth'], 0.0001);
        // Full price: order 1 (1) + order 4 (1 net) = 2 of 5.
        $this->assertEqualsWithDelta(0.4, $s['full_price_share'], 0.0001);
        $this->assertEqualsWithDelta(20.00, $s['order_discount'], 0.001);
        $this->assertEqualsWithDelta(24.00, $s['selling_plan_discount'], 0.001);
        $this->assertSame(1, $s['free_qty']);
        // Product ASP adds the $20 of Rivo back: (356 + 20) ÷ 5.
        $this->assertEqualsWithDelta(75.20, $s['product_asp'], 0.001);
    }

    public function test_product_asp_only_adds_back_order_discounts_on_kept_units(): void
    {
        // 2 × $80 with $20 of Rivo ($10 a unit); one unit refunded for its
        // net $70. The kept unit netted $70 and carried $10 of Rivo.
        $order = self::order(1, [self::lineItem(11, ['quantity' => 2, 'originalUnitPriceSet' => self::money(80.00), 'discountAllocations' => [
            self::allocation(20.00, 'ACROSS', 'DiscountCodeApplication', 'CAP-aaa111bbb222'),
        ]])], ['createdAt' => '2026-10-06T16:00:00Z', 'refunds' => [self::refund('1', [[11, 1, 70.00]])]]);
        (new SalesLineWriter)->write([(new OrderLineMapper('CP'))->map($order)]);

        $s = app(AspReportService::class)->summary($this->filters());

        $this->assertEqualsWithDelta(70.00, $s['asp'], 0.001);
        $this->assertEqualsWithDelta(80.00, $s['product_asp'], 0.001);
        $this->assertEqualsWithDelta(80.00, app(AspReportService::class)->breakdown($this->filters(), 'variant')[0]['product_asp'], 0.001);
    }

    public function test_filters_toggle_free_items_order_type_and_channel(): void
    {
        $this->seedSales();
        $service = app(AspReportService::class);

        $this->assertSame(6, $service->summary($this->filters(['include_free' => '1']))['net_qty']);
        $this->assertSame(1, $service->summary($this->filters(['order_type' => 'subscription']))['net_qty']);
        $this->assertSame(4, $service->summary($this->filters(['order_type' => 'one_time']))['net_qty']);
        $this->assertSame(6, $service->summary($this->filters(['channel' => AspReportService::CHANNEL_ALL]))['net_qty']);
        $this->assertSame(1, $service->summary($this->filters(['channel' => 'Draft Orders']))['net_qty']);
    }

    public function test_weekly_periods_are_continuous_and_carry_top_discounts(): void
    {
        $this->seedSales();

        $periods = app(AspReportService::class)->periods($this->filters(['granularity' => 'week']));

        $this->assertSame(['2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19'], array_column($periods, 'period'));
        // Orders 1 (Tue Oct 6) and 2 (Wed Oct 7) are in the week of Mon Oct 5.
        $this->assertSame(3, $periods[1]['net_qty']);
        $this->assertSame('Rivo loyalty', $periods[1]['top_labels'][0]['label']);
        $this->assertSame(AspReportService::SUBSCRIPTION_LABEL, $periods[2]['top_labels'][0]['label']);
        $this->assertSame(0, $periods[0]['net_qty']);
        $this->assertNull($periods[0]['asp']);
    }

    public function test_page_renders_and_requires_permission(): void
    {
        $this->seedSales();

        $this->actingAs(User::factory()->create())->get(route('tools.asp-tracker.index'))->assertForbidden();

        $this->actingAs($this->userWithAccess())
            ->get(route('tools.asp-tracker.index', ['from' => '2026-10-01', 'to' => '2026-10-20']))
            ->assertOk()
            ->assertSee('Canadian Protein Average Selling Price Tracker')
            ->assertSee('id="asp-key"', false)
            ->assertSee('$71.20')
            ->assertSee('Whey Protein Isolate - 1 kg (2.2 lb)')
            ->assertSee('Rivo loyalty');
    }

    public function test_biosteel_is_its_own_tool_with_its_own_data_and_default_view(): void
    {
        $this->seedSales();

        $bs = fn (int $id, array $line, array $order = []) => self::order($id, [self::lineItem($id * 10, $line)], $order + ['createdAt' => '2026-10-14T16:00:00Z']);
        (new SalesLineWriter)->write(array_map(fn ($o) => (new OrderLineMapper('BS'))->map($o), [
            $bs(101, ['originalUnitPriceSet' => self::money(50.00), 'discountAllocations' => [
                self::allocation(10.00, 'ACROSS', 'DiscountCodeApplication', 'BIO-0d26844c4003'),
                self::allocation(5.00, 'EACH', 'AutomaticDiscountApplication', 'BIO-STEAL'),
            ]]),
            // Left out of BioSteel's default view: part-paid influencer order, marketplace order.
            $bs(102, ['originalUnitPriceSet' => self::money(50.00), 'discountAllocations' => [self::allocation(40.00, 'ACROSS', 'ManualDiscountApplication', 'Influencer-hero-TO4N8K')]], ['sourceName' => '18652266497', 'app' => ['name' => 'Influencer Hero']]),
            $bs(103, ['originalUnitPriceSet' => self::money(44.00)], ['sourceName' => 'Amazon', 'app' => ['name' => 'GoDataFeed']]),
        ]));

        $service = app(AspReportService::class);
        $bsFilters = fn (array $input = []) => AspReportService::normalizeFilters($input + ['from' => '2026-10-01', 'to' => '2026-10-20'], 'BS');
        $this->assertSame(1, $service->summary($bsFilters())['net_qty']);
        $this->assertEqualsWithDelta(35.00, $service->summary($bsFilters())['asp'], 0.001);
        $this->assertSame(3, $service->summary($bsFilters(['channel' => AspReportService::CHANNEL_ALL]))['net_qty']);
        $this->assertSame(1, $service->summary($bsFilters(['channel' => 'GoDataFeed']))['net_qty']);
        // CP's numbers don't move, and CP's default view still only drops drafts.
        $this->assertSame(5, $service->summary($this->filters())['net_qty']);

        // BIO-<hex> is Rivo; the "BIO-STEAL" sale isn't.
        $groups = array_column($service->drilldown($bsFilters(['granularity' => 'day']), '2026-10-14')['groups'], 'amount', 'label');
        $this->assertEquals(['Rivo loyalty' => 10.00, 'BIO-STEAL' => 5.00], $groups);

        // Access is per tool: CP's permission doesn't open BioSteel's page.
        $this->actingAs($this->userWithAccess())->get(route('tools.asp-tracker-bs.index'))->assertForbidden();

        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo(Tool::where('slug', 'asp-tracker-bs')->firstOrFail()->permissionName());
        $this->actingAs($user)
            ->get(route('tools.asp-tracker-bs.index', ['from' => '2026-10-01', 'to' => '2026-10-20']))
            ->assertOk()
            ->assertSee('BioSteel Average Selling Price Tracker')
            ->assertSee('Retail (no drafts, Influencer Hero or marketplaces)')
            ->assertSee('$35.00')
            ->assertDontSee('$71.20');
        $this->actingAs($user)->get(route('tools.asp-tracker.index'))->assertForbidden();
    }

    public function test_empty_state_before_any_backfill(): void
    {
        $this->actingAs($this->userWithAccess())
            ->get(route('tools.asp-tracker.index'))
            ->assertOk()
            ->assertSee('No order history yet');
    }

    public function test_drilldown_lists_discount_groups_for_a_period(): void
    {
        $this->seedSales();

        $response = $this->actingAs($this->userWithAccess())
            ->getJson(route('tools.asp-tracker.drilldown', ['from' => '2026-10-01', 'to' => '2026-10-20', 'granularity' => 'week', 'period' => '2026-10-05']))
            ->assertOk();

        $this->assertSame('Rivo loyalty', $response->json('groups.0.label'));
        $this->assertSame(2, $response->json('groups.0.units'));
        $this->assertEquals(20, $response->json('groups.0.amount'));

        $month = $this->actingAs($this->userWithAccess())
            ->getJson(route('tools.asp-tracker.drilldown', ['from' => '2026-10-01', 'to' => '2026-10-20', 'granularity' => 'month', 'period' => '2026-10']))
            ->assertOk();
        $this->assertContains('Rivo loyalty', array_column($month->json('groups'), 'label'));
        $this->assertSame((new AspReportService)->summary($this->filters(['from' => '2026-10-01', 'to' => '2026-10-20']))['net_qty'], $month->json('summary.net_qty'));

        $this->actingAs($this->userWithAccess())
            ->getJson(route('tools.asp-tracker.drilldown', ['granularity' => 'week', 'period' => 'nope']))
            ->assertStatus(422);
    }

    public function test_csv_exports(): void
    {
        $this->seedSales();
        $user = $this->userWithAccess();

        $periods = $this->actingAs($user)->get(route('tools.asp-tracker.export-csv', ['from' => '2026-10-01', 'to' => '2026-10-20', 'granularity' => 'month']))->assertOk();
        $this->assertStringContainsString('2026-10,', $periods->getContent());
        $this->assertStringContainsString('71.20', $periods->getContent());

        $breakdown = $this->actingAs($user)->get(route('tools.asp-tracker.export-csv', ['table' => 'breakdown', 'from' => '2026-10-01', 'to' => '2026-10-20']))->assertOk();
        $this->assertStringContainsString('WPI-1KG-CHOC', $breakdown->getContent());
    }
}
