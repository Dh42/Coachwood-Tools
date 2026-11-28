<?php

namespace Tests\Feature\Console;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamSponsorshipBackfillOrderItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopify.store' => 'test-store.myshopify.com',
            'services.shopify.access_token' => 'test-token',
            'team_sponsorship.bottles' => ['label' => 'Bottles', 'product_id' => 111, 'variant_id' => 1111],
            'team_sponsorship.sample_packs' => ['label' => 'Sample Packs', 'product_id' => 222, 'variant_id' => 2222],
            'team_sponsorship.team_towels' => ['label' => 'Team Towels', 'product_id' => 333, 'variant_id' => 3333, 'per_bottles' => 5, 'max_per_order' => 3],
            'team_sponsorship.hockey_pucks' => ['label' => 'Hockey Pucks', 'product_id' => 444, 'variant_id' => 4444],
        ]);
    }

    public function test_it_backfills_line_items_for_orders_missing_them(): void
    {
        $order = Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 111000, 'sent_at' => now()]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/111000.json' => Http::response([
                'draft_order' => ['id' => 111000, 'line_items' => [
                    ['variant_id' => 1111, 'quantity' => 8, 'title' => 'BIOSTEEL TEAM BOTTLE'],
                    ['variant_id' => 2222, 'quantity' => 8, 'title' => 'HYDRATION MIX / Sample Pack'],
                ]],
            ]),
        ]);

        $this->artisan('team-sponsorship:backfill-order-items')->assertExitCode(0);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_label' => 'Bottles', 'quantity' => 8]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_label' => 'Sample Packs', 'quantity' => 8]);
    }

    public function test_it_falls_back_to_the_raw_shopify_title_for_an_unmatched_variant(): void
    {
        $order = Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 111001, 'sent_at' => now()]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/111001.json' => Http::response([
                'draft_order' => ['id' => 111001, 'line_items' => [
                    ['variant_id' => 999999, 'quantity' => 1, 'title' => 'Discontinued Widget'],
                ]],
            ]),
        ]);

        $this->artisan('team-sponsorship:backfill-order-items')->assertExitCode(0);

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_label' => 'Discontinued Widget', 'quantity' => 1]);
    }

    public function test_it_skips_orders_that_already_have_items(): void
    {
        $order = Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 111002, 'sent_at' => now()]);
        $order->items()->create(['product_label' => 'Bottles', 'quantity' => 3]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/111002.json' => Http::response(['draft_order' => ['id' => 111002, 'line_items' => []]]),
        ]);

        $this->artisan('team-sponsorship:backfill-order-items')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_running_it_twice_does_not_duplicate_items(): void
    {
        $order = Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 111003, 'sent_at' => now()]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/111003.json' => Http::response([
                'draft_order' => ['id' => 111003, 'line_items' => [['variant_id' => 1111, 'quantity' => 4, 'title' => 'Bottle']]],
            ]),
        ]);

        $this->artisan('team-sponsorship:backfill-order-items')->assertExitCode(0);
        $this->artisan('team-sponsorship:backfill-order-items')->assertExitCode(0);

        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 111004, 'sent_at' => now()]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/111004.json' => Http::response([
                'draft_order' => ['id' => 111004, 'line_items' => [['variant_id' => 1111, 'quantity' => 4, 'title' => 'Bottle']]],
            ]),
        ]);

        $this->artisan('team-sponsorship:backfill-order-items', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, OrderItem::count());
    }

    public function test_a_failed_order_does_not_abort_the_rest_of_the_run(): void
    {
        $failing = Order::create(['email' => 'a@example.com', 'team_name' => 'Alpha', 'shopify_order_id' => 111005, 'sent_at' => now()]);
        $succeeding = Order::create(['email' => 'b@example.com', 'team_name' => 'Beta', 'shopify_order_id' => 111006, 'sent_at' => now()]);

        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/111005.json' => Http::response(['error' => 'Not found'], 404),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/111006.json' => Http::response([
                'draft_order' => ['id' => 111006, 'line_items' => [['variant_id' => 1111, 'quantity' => 2, 'title' => 'Bottle']]],
            ]),
        ]);

        $this->artisan('team-sponsorship:backfill-order-items')->assertExitCode(0);

        $this->assertSame(0, OrderItem::where('order_id', $failing->id)->count());
        $this->assertDatabaseHas('order_items', ['order_id' => $succeeding->id, 'product_label' => 'Bottles', 'quantity' => 2]);
    }
}
