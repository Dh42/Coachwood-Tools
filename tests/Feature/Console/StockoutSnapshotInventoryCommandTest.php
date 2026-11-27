<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockoutSnapshotInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stockout.shopify.store' => 'shopify-test.myshopify.com',
            'stockout.shopify.access_token' => 'test-token',
            'stockout.api_version' => '2026-07',
            'stockout.tracked_states' => ['available', 'committed', 'on_hand'],
        ]);
    }

    private function page(bool $hasNextPage, string $cursor = ''): array
    {
        return ['data' => ['productVariants' => [
            'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $cursor],
            'edges' => [[
                'node' => [
                    'id' => 'gid://shopify/ProductVariant/1',
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/10',
                        'tracked' => true,
                        'inventoryLevels' => ['edges' => [[
                            'node' => [
                                'location' => ['id' => 'gid://shopify/Location/100'],
                                'quantities' => [
                                    ['name' => 'available', 'quantity' => 5],
                                    ['name' => 'committed', 'quantity' => 1],
                                    ['name' => 'on_hand', 'quantity' => 6],
                                ],
                            ],
                        ]]],
                    ],
                ],
            ]],
        ]]];
    }

    public function test_it_writes_one_row_per_state_per_variant_location(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response($this->page(false)),
        ]);

        $this->artisan('stockout:snapshot-inventory')->assertExitCode(0);

        $this->assertDatabaseCount('inventory_snapshots', 3);
        $this->assertDatabaseHas('inventory_snapshots', [
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'location_id' => 'gid://shopify/Location/100',
            'state' => 'available',
            'quantity' => 5,
            'source' => 'cron',
        ]);
    }

    public function test_it_paginates_until_hasnextpage_is_false(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push($this->page(true, 'cursor-1'))
                ->push($this->page(false)),
        ]);

        $this->artisan('stockout:snapshot-inventory')->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('inventory_snapshots', 6); // 3 states x 2 pages
    }

    public function test_untracked_inventory_items_are_skipped(): void
    {
        $page = $this->page(false);
        $page['data']['productVariants']['edges'][0]['node']['inventoryItem']['tracked'] = false;

        Http::fake(['shopify-test.myshopify.com/*' => Http::response($page)]);

        $this->artisan('stockout:snapshot-inventory')->assertExitCode(0);

        $this->assertDatabaseCount('inventory_snapshots', 0);
    }

    public function test_a_shopify_error_fails_the_command_without_a_partial_write(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response(['errors' => [['message' => 'Something broke']]]),
        ]);

        $this->artisan('stockout:snapshot-inventory')->assertExitCode(1);

        $this->assertDatabaseCount('inventory_snapshots', 0);
    }

    public function test_the_sweep_maps_each_inventory_item_to_its_variant_once(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push($this->page(true, 'cursor-1'))
                ->push($this->page(false))
                ->push($this->page(false)),
        ]);

        // Two pages naming the same item, then a second run: still one mapping row.
        $this->artisan('stockout:snapshot-inventory')->assertExitCode(0);
        $this->artisan('stockout:snapshot-inventory')->assertExitCode(0);

        $this->assertDatabaseCount('inventory_item_variants', 1);
        $this->assertDatabaseHas('inventory_item_variants', [
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/1',
        ]);
    }
}
