<?php

namespace Tests\Feature\Tools;

use App\Models\Order;
use App\Models\TeamSponsorshipActivity;
use App\Models\Tool;
use App\Models\User;
use App\Services\TeamSponsorship\TeamActivityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamSponsorshipTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected Tool $statsTool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);

        // The tools + their permissions are registered automatically by
        // migrations, which run as part of RefreshDatabase — no need to
        // create them here.
        $this->tool = Tool::where('slug', 'team-sponsorship')->firstOrFail();
        $this->statsTool = Tool::where('slug', 'team-sponsorship-stats')->firstOrFail();

        config([
            // These tests check what the real write sends; the suite runs
            // with writes off (Tests\TestCase), so they switch it on.
            'tools.external_writes' => true,
            'services.biosteel_teams.base_url' => 'https://api.biosteelteams.test',
            'services.biosteel_teams.username' => 'app@biosteel.com',
            'services.biosteel_teams.password' => 'secret',
            'services.shopify.store' => 'test-store.myshopify.com',
            'services.shopify.access_token' => 'test-token',
            'klaviyo.accounts.BS' => ['name' => 'BioSteel', 'private_api_key' => 'test-bs-key'],
            'klaviyo.events.claimed_sponsorship_package' => 'Claimed Sponsorship Package',
        ]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/variants/*' => Http::response([
                'variant' => ['title' => 'Default', 'sku' => 'SKU1', 'inventory_quantity' => 10],
            ]),
        ]);
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    protected function userWithStatsAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->statsTool->permissionName());

        return $user;
    }

    public function test_users_without_the_tool_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.team-sponsorship.index'))->assertForbidden();
        $this->actingAs($user)->post(route('tools.team-sponsorship.search'), ['email' => 'a@b.com'])->assertForbidden();
    }

    public function test_admins_can_access_the_tool_without_the_permission_checkbox(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('tools.team-sponsorship.index'))->assertOk();
    }

    public function test_index_shows_the_inventory_snapshot(): void
    {
        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-sponsorship.index'));

        $response->assertOk();
        $response->assertSee('Default');
        $response->assertSee('SKU1');
    }

    public function test_users_without_the_stats_permission_cannot_view_stats(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.team-sponsorship.stats'))->assertForbidden();
    }

    public function test_stats_and_the_main_tool_are_independently_grantable(): void
    {
        // Has the main tool's permission, but not stats.
        $mainOnly = $this->userWithAccess();
        $this->actingAs($mainOnly)->get(route('tools.team-sponsorship.index'))->assertOk();
        $this->actingAs($mainOnly)->get(route('tools.team-sponsorship.stats'))->assertForbidden();

        // Has the stats permission, but not the main tool.
        $statsOnly = $this->userWithStatsAccess();
        $this->actingAs($statsOnly)->get(route('tools.team-sponsorship.stats'))->assertOk();
        $this->actingAs($statsOnly)->get(route('tools.team-sponsorship.index'))->assertForbidden();
    }

    public function test_stats_page_shows_totals_top_requesters_and_recent_orders(): void
    {
        Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 1, 'sent_at' => now()]);
        Order::create(['email' => 'a@example.com', 'team_name' => 'Beta', 'shopify_order_id' => 2, 'sent_at' => now()]);
        Order::create(['email' => 'b@example.com', 'team_name' => 'Gamma', 'shopify_order_id' => 3, 'sent_at' => now()->subMonth()]);

        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats'));

        $response->assertOk();
        $response->assertSee('Alpha');
        $response->assertSee('a@example.com');
        $response->assertViewHas('totalOrders', 3);
        $response->assertViewHas('uniqueRequesters', 2);
    }

    public function test_stats_page_shows_products_sent_by_month(): void
    {
        config([
            'team_sponsorship.bottles' => ['label' => 'Bottles', 'product_id' => 111, 'variant_id' => 1111],
            'team_sponsorship.sample_packs' => ['label' => 'Sample Packs', 'product_id' => 222, 'variant_id' => 2222],
            'team_sponsorship.team_towels' => ['label' => 'Team Towels', 'product_id' => 333, 'variant_id' => 3333, 'per_bottles' => 5, 'max_per_order' => 3],
            'team_sponsorship.hockey_pucks' => ['label' => 'Hockey Pucks', 'product_id' => 444, 'variant_id' => 4444],
        ]);

        $augustOrder = Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 1, 'sent_at' => '2026-08-15']);
        $augustOrder->items()->create(['product_label' => 'Bottles', 'quantity' => 10]);
        $septemberOrder = Order::create(['email' => 'b@example.com', 'team_name' => 'Beta', 'shopify_order_id' => 2, 'sent_at' => '2026-09-01']);
        $septemberOrder->items()->create(['product_label' => 'Bottles', 'quantity' => 5]);
        // Simulates a backfilled historical item whose product no longer matches current config.
        $septemberOrder->items()->create(['product_label' => 'Discontinued Widget', 'quantity' => 1]);

        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats'));

        $response->assertOk();
        $productsByMonth = $response->viewData('productsByMonth');

        $this->assertSame(['Aug 2026', 'Sep 2026'], $productsByMonth['months']->toArray());
        $this->assertSame([10, 5], $productsByMonth['products']['Bottles']->toArray());
        $this->assertSame([0, 1], $productsByMonth['products']['Other']->toArray());

        // (10 + 5) / 3 = 5.0, always divided by 3 per the requested formula —
        // but months_counted honestly reports only 2 real months exist yet.
        $this->assertEquals(5.0, $productsByMonth['monthly_averages']['Bottles']['average']);
        $this->assertSame(2, $productsByMonth['monthly_averages']['Bottles']['months_counted']);
        $response->assertSee('based on 2 mo of data');
    }

    public function test_products_monthly_average_does_not_flag_short_history_once_three_months_exist(): void
    {
        config(['team_sponsorship.bottles' => ['label' => 'Bottles', 'product_id' => 111, 'variant_id' => 1111]]);

        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $i => $date) {
            $order = Order::create(['email' => "u{$i}@example.com", 'team_name' => "Team {$i}", 'shopify_order_id' => 100 + $i, 'sent_at' => $date]);
            $order->items()->create(['product_label' => 'Bottles', 'quantity' => 6]);
        }

        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats'));
        $productsByMonth = $response->viewData('productsByMonth');

        // (6 + 6 + 6) / 3 = 6.0
        $this->assertEquals(6.0, $productsByMonth['monthly_averages']['Bottles']['average']);
        $this->assertSame(3, $productsByMonth['monthly_averages']['Bottles']['months_counted']);
        $response->assertDontSee('based on 3 mo of data');
    }

    public function test_stats_page_shows_a_products_empty_state_with_no_data(): void
    {
        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats'));

        $response->assertOk();
        $response->assertSee('backfill command');
    }

    public function test_stats_page_shows_activity_counts_for_matched_teams_and_a_dash_when_unmatched(): void
    {
        Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 1, 'sent_at' => now()]);
        Order::create(['email' => 'b@example.com', 'team_name' => 'Beta', 'shopify_order_id' => 2, 'sent_at' => now()]);

        TeamSponsorshipActivity::create([
            'email' => 'a@example.com',
            'team_name' => 'Alpha',
            'team_id' => 'team-a',
            'user_id' => 'user-a',
            'emails_count' => 1234,
            'events_count' => 5678,
            'games_count' => 391,
            'practices_count' => 246,
            'refreshed_at' => now(),
        ]);
        // No activity row at all for "Beta" — refresh hasn't run for it yet.

        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats'));

        $response->assertOk();
        // number_format's thousands separator makes these distinctive substrings.
        $response->assertSee('1,234');
        $response->assertSee('5,678');
        $response->assertSee('391');
        $response->assertSee('246');
        $response->assertSee('—'); // Beta's unmatched row
    }

    public function test_stats_export_requires_the_stats_permission(): void
    {
        $this->actingAs($this->userWithAccess())->get(route('tools.team-sponsorship.stats.export'))->assertForbidden();
    }

    public function test_stats_export_includes_every_sponsorship_newest_first_with_activity_counts(): void
    {
        // 25 orders — more than the grid's 20-per-page, so a page-limited
        // export would visibly drop the oldest ones.
        for ($i = 1; $i <= 25; $i++) {
            Order::create(['email' => "t{$i}@example.com", 'team_name' => "Team {$i}", 'shopify_order_id' => 1000 + $i, 'sent_at' => now()->subDays(30 - $i)]);
        }
        TeamSponsorshipActivity::create([
            'email' => 't25@example.com', 'team_name' => 'Team 25',
            'emails_count' => 17, 'events_count' => 33, 'games_count' => 17, 'practices_count' => 16, 'refreshed_at' => now(),
        ]);

        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $rows = array_map('str_getcsv', array_filter(explode("\n", $response->streamedContent())));

        $this->assertSame(['Date', 'Team', 'Email', 'Shopify Draft Order ID', 'Emails', 'Events', 'Games', 'Practices'], $rows[0]);
        $this->assertCount(26, $rows); // header + all 25
        $this->assertSame(['Team 25', 't25@example.com', '1025', '17', '33', '17', '16'], array_slice($rows[1], 1));
        // No activity data -> blank cells, not zeros.
        $this->assertSame(['Team 1', 't1@example.com', '1001', '', '', '', ''], array_slice($rows[25], 1));
    }

    public function test_stats_page_computes_no_events_percent_and_total_emails_captured(): void
    {
        // Alpha: refreshed, 0 events -> counts toward "no events".
        Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 1, 'sent_at' => now()]);
        TeamSponsorshipActivity::create([
            'email' => 'a@example.com', 'team_name' => 'Alpha', 'emails_count' => 10, 'events_count' => 0, 'refreshed_at' => now(),
        ]);

        // Beta: refreshed, has events -> counts in the denominator, not the numerator.
        Order::create(['email' => 'b@example.com', 'team_name' => 'Beta', 'shopify_order_id' => 2, 'sent_at' => now()]);
        TeamSponsorshipActivity::create([
            'email' => 'b@example.com', 'team_name' => 'Beta', 'emails_count' => 20, 'events_count' => 5, 'refreshed_at' => now(),
        ]);

        // Gamma: never refreshed (no activity row at all) -> excluded entirely,
        // not counted as "no events" just because we have no data.
        Order::create(['email' => 'c@example.com', 'team_name' => 'Gamma', 'shopify_order_id' => 3, 'sent_at' => now()]);

        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats'));

        $response->assertOk();
        $response->assertViewHas('ordersWithKnownEvents', 2); // Alpha + Beta only, not Gamma
        $response->assertViewHas('ordersWithNoEvents', 1); // Alpha only
        $response->assertViewHas('noEventsPct', 50.0);
        $response->assertViewHas('emailsTotal', 30); // Alpha (10) + Beta (20); Gamma has no activity row to sum
        $response->assertViewHas('teamsWithEmailsData', 2);
    }

    public function test_stats_page_shows_a_dash_for_no_events_percent_when_theres_no_refreshed_data(): void
    {
        Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 1, 'sent_at' => now()]);

        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats'));

        $response->assertOk();
        $response->assertViewHas('noEventsPct', null);
        $response->assertViewHas('emailsTotal', 0);
        $response->assertSee('No refreshed activity data yet');
    }

    public function test_stats_page_renders_with_no_data_yet(): void
    {
        $response = $this->actingAs($this->userWithStatsAccess())->get(route('tools.team-sponsorship.stats'));

        $response->assertOk();
        $response->assertSee('No sponsorship data yet.');
        $response->assertSee('No sponsorships recorded yet.');
    }

    public function test_search_shows_create_order_button_for_unsent_teams_and_sent_status_for_others(): void
    {
        Order::create([
            'email' => 'lesley@biosteel.com',
            'team_name' => 'Trader hoes',
            'shopify_order_id' => 923749515313,
            'sent_at' => now(),
        ]);

        Http::fake([
            'api.biosteelteams.test/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            'api.biosteelteams.test/api/admin/teams/all' => Http::response([
                ['teamName' => 'Trader hoes', 'teamOwner' => 'Lesley', 'numberOfRegisteredPlayers' => 1],
                ['teamName' => 'Brand New Team', 'teamOwner' => 'Lesley', 'numberOfRegisteredPlayers' => 1],
            ]),
            'api.biosteelteams.test/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [
                    ['teamName' => 'Trader hoes', 'sportType' => 'HOCKEY', 'adminEmails' => ['lesley@biosteel.com']],
                ],
                'metadata' => ['last' => true],
            ], 200),
            // No teamId in the sport-type-admin-emails fixture above, so
            // events_count resolves to null before ever needing a
            // userId — this endpoint still gets hit unconditionally though
            // (see safeFindUserId()), so it still needs faking here to
            // avoid a real network attempt during the test.
            'api.biosteelteams.test/api/users/search*' => Http::response([], 200),
            'test-store.myshopify.com/*' => Http::response([
                'variant' => ['title' => 'Default', 'sku' => 'SKU1', 'inventory_quantity' => 10],
            ]),
        ]);

        $response = $this->actingAs($this->userWithAccess())
            ->post(route('tools.team-sponsorship.search'), ['email' => 'lesley@biosteel.com']);

        $response->assertOk();
        $response->assertSee('923749515313');
        $response->assertSeeInOrder(['Brand New Team', 'Create Order']);
        $response->assertSeeInOrder(['Trader hoes', 'Hockey']);
    }

    public function test_search_shows_the_live_scheduled_events_count_when_it_resolves(): void
    {
        Http::fake([
            'api.biosteelteams.test/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            'api.biosteelteams.test/api/admin/teams/all' => Http::response([
                ['teamName' => 'Trader hoes', 'teamOwner' => 'Lesley', 'numberOfRegisteredPlayers' => 1],
            ]),
            'api.biosteelteams.test/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [
                    ['teamId' => 'team-a', 'teamName' => 'Trader hoes', 'sportType' => 'HOCKEY', 'adminEmails' => ['lesley@biosteel.com']],
                ],
                'metadata' => ['last' => true],
            ], 200),
            'api.biosteelteams.test/api/users/search*' => Http::response([
                ['userId' => 'user-a', 'name' => 'Lesley'],
            ], 200),
            'api.biosteelteams.test/api/users/user-a/teams/team-a/events' => Http::response(array_fill(0, 42, []), 200),
        ]);

        $response = $this->actingAs($this->userWithAccess())
            ->post(route('tools.team-sponsorship.search'), ['email' => 'lesley@biosteel.com']);

        $response->assertOk();
        $response->assertSeeInOrder(['Scheduled Events:', '42']);
    }

    public function test_search_still_shows_the_events_count_when_the_owner_cant_be_found_by_email(): void
    {
        // Real case (a coach's address, 2026-09-24; not repeated here): the team
        // resolves, but /api/users/search returns nothing for the email.
        // The events endpoint ignores the path userId, so the count must
        // still be fetched via the placeholder rather than skipped.
        $placeholder = TeamActivityClient::PLACEHOLDER_USER_ID;

        Http::fake([
            'api.biosteelteams.test/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            'api.biosteelteams.test/api/admin/teams/all' => Http::response([
                ['teamName' => 'Trader hoes', 'teamOwner' => 'Lesley', 'numberOfRegisteredPlayers' => 1],
            ]),
            'api.biosteelteams.test/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [
                    ['teamId' => 'team-a', 'teamName' => 'Trader hoes', 'sportType' => 'HOCKEY', 'adminEmails' => ['lesley@biosteel.com']],
                ],
                'metadata' => ['last' => true],
            ], 200),
            'api.biosteelteams.test/api/users/search*' => Http::response([], 200),
            "api.biosteelteams.test/api/users/{$placeholder}/teams/team-a/events" => Http::response(array_fill(0, 33, []), 200),
        ]);

        $response = $this->actingAs($this->userWithAccess())
            ->post(route('tools.team-sponsorship.search'), ['email' => 'lesley@biosteel.com']);

        $response->assertOk();
        $response->assertSeeInOrder(['Scheduled Events:', '33']);
    }

    public function test_search_shows_a_dash_for_scheduled_events_when_the_team_id_cant_be_resolved(): void
    {
        Http::fake([
            'api.biosteelteams.test/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            'api.biosteelteams.test/api/admin/teams/all' => Http::response([
                ['teamName' => 'Trader hoes', 'teamOwner' => 'Lesley', 'numberOfRegisteredPlayers' => 1],
            ]),
            // No matching row at all in the org-wide roster fake -- teamId
            // never resolves, so events_count must degrade to null/dash
            // without ever attempting the per-team events call.
            'api.biosteelteams.test/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [], 'metadata' => ['last' => true],
            ], 200),
            'api.biosteelteams.test/api/users/search*' => Http::response([
                ['userId' => 'user-a', 'name' => 'Lesley'],
            ], 200),
        ]);

        $response = $this->actingAs($this->userWithAccess())
            ->post(route('tools.team-sponsorship.search'), ['email' => 'lesley@biosteel.com']);

        $response->assertOk();
        $response->assertSeeInOrder(['Scheduled Events:', '—']);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/events'));
    }

    public function test_search_returns_only_the_results_fragment_not_the_full_page(): void
    {
        Http::fake([
            'api.biosteelteams.test/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer tok']),
            'api.biosteelteams.test/api/admin/teams/all' => Http::response([]),
            'api.biosteelteams.test/api/admin/teams/sport-type-admin-emails*' => Http::response([
                'content' => [], 'metadata' => ['last' => true],
            ], 200),
            'api.biosteelteams.test/api/users/search*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->userWithAccess())
            ->post(route('tools.team-sponsorship.search'), ['email' => 'nobody@example.com']);

        $response->assertOk();
        $response->assertDontSee('Coachwood Group Tools');
        $response->assertDontSee('Get Teams');
    }

    public function test_matching_is_case_insensitive_on_email_and_team_name(): void
    {
        Order::create([
            'email' => 'Lesley@Biosteel.com',
            'team_name' => 'trader hoes',
            'shopify_order_id' => 111222333,
            'sent_at' => now(),
        ]);

        $found = Order::hasBeenSentFor('lesley@biosteel.com', 'TRADER HOES');

        $this->assertNotNull($found);
        $this->assertSame(111222333, $found->shopify_order_id);
    }

    public function test_store_order_creates_a_record_and_returns_json(): void
    {
        config([
            'team_sponsorship.bottles' => ['label' => 'Bottles', 'product_id' => 111, 'variant_id' => 1111],
            'team_sponsorship.sample_packs' => ['label' => 'Sample Packs', 'product_id' => 222, 'variant_id' => 2222],
            'team_sponsorship.team_towels' => [
                'label' => 'Team Towels', 'product_id' => 333, 'variant_id' => 3333,
                'per_bottles' => 5, 'max_per_order' => 3,
            ],
        ]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/products/*' => Http::response([
                'product' => ['variants' => [['id' => 1111, 'inventory_item_id' => 1], ['id' => 2222, 'inventory_item_id' => 2]]],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 999888, 'name' => '#D1'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/999888/send_invoice.json' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.team-sponsorship.orders.store'), [
            'email' => 'new@example.com',
            'teamName' => 'New Team',
            'quantity' => 2,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'orderId' => 999888]);

        $this->assertDatabaseHas('orders', [
            'email' => 'new@example.com',
            'team_name' => 'New Team',
            'shopify_order_id' => 999888,
        ]);

        $order = Order::where('shopify_order_id', 999888)->firstOrFail();
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_label' => 'Bottles', 'quantity' => 2]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_label' => 'Sample Packs', 'quantity' => 2]);
    }

    public function test_store_order_fires_a_claimed_sponsorship_package_klaviyo_event(): void
    {
        config([
            'team_sponsorship.bottles' => ['label' => 'Bottles', 'product_id' => 111, 'variant_id' => 1111],
            'team_sponsorship.sample_packs' => ['label' => 'Sample Packs', 'product_id' => 222, 'variant_id' => 2222],
            'team_sponsorship.team_towels' => [
                'label' => 'Team Towels', 'product_id' => 333, 'variant_id' => 3333,
                'per_bottles' => 5, 'max_per_order' => 3,
            ],
        ]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/products/*' => Http::response([
                'product' => ['variants' => [['id' => 1111, 'inventory_item_id' => 1], ['id' => 2222, 'inventory_item_id' => 2]]],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 999890, 'name' => '#D3'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/999890/send_invoice.json' => Http::response([], 200),
            'a.klaviyo.com/*' => Http::response('', 202),
        ]);

        $this->actingAs($this->userWithAccess())->postJson(route('tools.team-sponsorship.orders.store'), [
            'email' => 'new@example.com',
            'teamName' => 'New Team',
            'quantity' => 2,
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'a.klaviyo.com')) {
                return true;
            }
            $attrs = $request['data']['attributes'];

            return $request->hasHeader('Authorization', 'Klaviyo-API-Key test-bs-key')
                && $attrs['metric']['data']['attributes']['name'] === 'Claimed Sponsorship Package'
                && $attrs['profile']['data']['attributes']['email'] === 'new@example.com'
                && $attrs['properties']['team_name'] === 'New Team'
                && $attrs['properties']['order_id'] === 999890
                && $attrs['properties']['order_name'] === '#D3';
        });
    }

    public function test_store_order_includes_hockey_pucks_at_bottle_quantity_when_sport_is_hockey(): void
    {
        config([
            'team_sponsorship.bottles' => ['label' => 'Bottles', 'product_id' => 111, 'variant_id' => 1111],
            'team_sponsorship.sample_packs' => ['label' => 'Sample Packs', 'product_id' => 222, 'variant_id' => 2222],
            'team_sponsorship.team_towels' => [
                'label' => 'Team Towels', 'product_id' => 333, 'variant_id' => 3333,
                'per_bottles' => 5, 'max_per_order' => 3,
            ],
            'team_sponsorship.hockey_pucks' => ['label' => 'Hockey Pucks', 'product_id' => 444, 'variant_id' => 4444],
        ]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/products/*' => Http::response([
                'product' => ['variants' => [
                    ['id' => 1111, 'inventory_item_id' => 1], ['id' => 2222, 'inventory_item_id' => 2], ['id' => 4444, 'inventory_item_id' => 4],
                ]],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 999889, 'name' => '#D2'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/999889/send_invoice.json' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.team-sponsorship.orders.store'), [
            'email' => 'new@example.com',
            'teamName' => 'Ice Team',
            'quantity' => 3,
            'sport' => 'HOCKEY',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2024-01/draft_orders.json') {
                return true;
            }

            $puckLine = collect($request->data()['draft_order']['line_items'])->firstWhere('variant_id', 4444);

            return $puckLine !== null && $puckLine['quantity'] === 3;
        });
    }

    public function test_index_shows_hockey_puck_stock_in_the_inventory_snapshot(): void
    {
        config(['team_sponsorship.hockey_pucks' => ['label' => 'Hockey Pucks', 'product_id' => 444, 'variant_id' => 4444]]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/variants/4444.json' => Http::response([
                'variant' => ['title' => 'Default', 'sku' => 'B-HP', 'inventory_quantity' => 25],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/variants/*' => Http::response([
                'variant' => ['title' => 'Default', 'sku' => 'SKU1', 'inventory_quantity' => 10],
            ]),
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.team-sponsorship.index'));

        $response->assertOk();
        $response->assertSee('Hockey Pucks');
        // Changed in the port: setUp's catch-all variants fake is matched
        // first, so the 25 above is never served, and the old page only
        // passed because "25" appeared elsewhere in its HTML. Check instead
        // that the puck variant is what gets fetched.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/admin/api/2024-01/variants/4444.json'));
    }
}
