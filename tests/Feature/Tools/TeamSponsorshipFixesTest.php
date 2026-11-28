<?php

namespace Tests\Feature\Tools;

use App\Models\Order;
use App\Models\Tool;
use App\Models\User;
use App\Services\Klaviyo\KlaviyoClient;
use App\Services\Shopify\ShopifyClient;
use App\Support\ExternalWritesDisabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Team Sponsorship behaviour that differs from the old app on purpose: the
 * write guard (plan section 2, rule 3) and the bugs fixed in the port
 * (docs/found-issues.md 1, 32 and 33). Every value is invented.
 */
class TeamSponsorshipFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'user']);
        config([
            'services.biosteel_teams.base_url' => 'https://api.biosteelteams.test',
            'services.biosteel_teams.username' => 'service@example.com',
            'services.biosteel_teams.password' => 'secret',
            'services.shopify.store' => 'test-store.myshopify.com',
            'services.shopify.access_token' => 'test-token',
            'klaviyo.accounts.BS' => ['name' => 'BioSteel', 'private_api_key' => 'test-bs-key'],
            'team_sponsorship.bottles' => ['label' => 'Bottles', 'product_id' => 111, 'variant_id' => 1111],
            'team_sponsorship.sample_packs' => ['label' => 'Sample Packs', 'product_id' => 222, 'variant_id' => 2222],
            'team_sponsorship.team_towels' => ['label' => 'Team Towels', 'product_id' => 333, 'variant_id' => 3333, 'per_bottles' => 5, 'max_per_order' => 3],
            'team_sponsorship.hockey_pucks' => ['label' => 'Hockey Pucks', 'product_id' => 444, 'variant_id' => 4444],
        ]);
    }

    private function user(string $slug = 'team-sponsorship'): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo(Tool::where('slug', $slug)->firstOrFail()->permissionName());

        return $user;
    }

    private function fakeShopifyOrder(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/products/*' => Http::response([
                'product' => ['variants' => [['id' => 1111, 'inventory_item_id' => 1], ['id' => 2222, 'inventory_item_id' => 2], ['id' => 3333, 'inventory_item_id' => 3], ['id' => 4444, 'inventory_item_id' => 4]]],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response(['draft_order' => ['id' => 555001, 'name' => '#D9']]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/555001/send_invoice.json' => Http::response([], 200),
            'a.klaviyo.com/*' => Http::response('', 202),
        ]);
    }

    public function test_with_writes_off_an_order_sends_nothing_and_says_what_it_would_have_sent(): void
    {
        $this->fakeShopifyOrder();

        $response = $this->actingAs($this->user())->postJson(route('tools.team-sponsorship.orders.store'), [
            'email' => 'coach@example.com', 'teamName' => 'Made-up Rink', 'quantity' => 12, 'sport' => 'HOCKEY',
        ]);

        $response->assertOk()->assertJson([
            'success' => false,
            'writesDisabled' => true,
            'lineItems' => [
                ['label' => 'Bottles', 'quantity' => 12],
                ['label' => 'Sample Packs', 'quantity' => 12],
                ['label' => 'Team Towels', 'quantity' => 2],
                ['label' => 'Hockey Pucks', 'quantity' => 12],
            ],
        ]);
        $this->assertStringContainsString('nothing was sent', $response->json('error'));
        Http::assertNothingSent();
        $this->assertSame(0, Order::count());
    }

    public function test_with_writes_off_the_klaviyo_client_sends_nothing(): void
    {
        Http::fake();

        $result = (new KlaviyoClient('BS'))->sendEvent('Made-up Event', 'coach@example.com');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('External writes are disabled', $result['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_with_writes_off_the_shopify_client_refuses_before_any_call(): void
    {
        Http::fake();

        try {
            (new ShopifyClient)->createSponsorshipDraftOrder('coach@example.com', 'Made-up Rink', 4);
            $this->fail('Expected the draft order to be refused.');
        } catch (ExternalWritesDisabled $e) {
            $this->assertStringContainsString('Shopify draft order', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_the_page_says_orders_are_off_until_cutover(): void
    {
        Http::fake(['test-store.myshopify.com/*' => Http::response(['variant' => ['title' => 'Default', 'sku' => 'SKU1', 'inventory_quantity' => 1]])]);

        $this->actingAs($this->user())->get(route('tools.team-sponsorship.index'))
            ->assertOk()->assertSee('Orders are off in this app until cutover.');

        config(['tools.external_writes' => true]);
        $this->actingAs($this->user())->get(route('tools.team-sponsorship.index'))
            ->assertOk()->assertDontSee('Orders are off in this app until cutover.');
    }

    /** Found issue 1: only the button stopped a second package going to the same team. */
    public function test_a_team_that_already_has_an_order_is_refused_without_calling_shopify(): void
    {
        config(['tools.external_writes' => true]);
        $this->fakeShopifyOrder();
        Order::create(['email' => 'Coach@Example.com', 'team_name' => 'made-up rink', 'shopify_order_id' => 777, 'sent_at' => '2026-10-01 12:00:00']);

        $response = $this->actingAs($this->user())->postJson(route('tools.team-sponsorship.orders.store'), [
            'email' => 'coach@example.com', 'teamName' => 'Made-up Rink', 'quantity' => 3,
        ]);

        $response->assertOk()->assertJson(['success' => false]);
        $this->assertStringContainsString('already', $response->json('error'));
        Http::assertNothingSent();
        $this->assertSame(1, Order::count());
    }

    /** Found issue 32: a connection timeout used to be a 500 for the whole page. */
    public function test_a_shopify_timeout_shows_an_inventory_error_instead_of_failing_the_page(): void
    {
        Http::fake(['test-store.myshopify.com/*' => Http::failedConnection()]);

        $this->actingAs($this->user())->get(route('tools.team-sponsorship.index'))
            ->assertOk()
            ->assertSee('Get Teams')
            ->assertSee("Couldn't load stock from Shopify.");
    }

    /** Found issue 32: with no store configured, the request URL was malformed and the page failed. */
    public function test_an_unconfigured_shopify_store_shows_an_inventory_error_instead_of_failing_the_page(): void
    {
        config(['services.shopify.store' => null]);
        Http::fake();

        $this->actingAs($this->user())->get(route('tools.team-sponsorship.index'))
            ->assertOk()
            ->assertSee("Couldn't load stock from Shopify.")
            ->assertSee('SHOPIFY_STORE');
        Http::assertNothingSent();
    }

    /** Found issue 32: the same for the team search, against the BioSteel Teams API. */
    public function test_a_teams_api_timeout_shows_an_error_instead_of_failing_the_search(): void
    {
        Http::fake([
            'api.biosteelteams.test/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            'api.biosteelteams.test/*' => Http::failedConnection(),
        ]);

        $this->actingAs($this->user())->post(route('tools.team-sponsorship.search'), ['email' => 'coach@example.com'])
            ->assertOk()
            ->assertSee("Couldn't reach the BioSteel Teams API");
    }

    /** Found issue 33: team names are typed by coaches, so a leading = must not reach a spreadsheet as a formula. */
    public function test_the_export_keeps_formula_like_cells_as_text(): void
    {
        Order::create(['email' => 'coach@example.com', 'team_name' => '=HYPERLINK("http://example.test","Made-up")', 'shopify_order_id' => 901, 'sent_at' => '2026-10-01 12:00:00']);
        Order::create(['email' => '+coach@example.com', 'team_name' => 'Made-up Plain Team', 'shopify_order_id' => 902, 'sent_at' => '2026-10-02 12:00:00']);

        $response = $this->actingAs($this->user('team-sponsorship-stats'))->get(route('tools.team-sponsorship.stats.export'));

        $rows = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));
        $this->assertSame(["'+coach@example.com", 'Made-up Plain Team'], [$rows[1][2], $rows[1][1]]);
        $this->assertSame('\'=HYPERLINK("http://example.test","Made-up")', $rows[2][1]);
        $this->assertSame('901', $rows[2][3]);
    }
}
