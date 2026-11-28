<?php

namespace Tests\Unit\Services;

use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ShopifyClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            // These tests check what the real write sends; the suite runs
            // with writes off (Tests\TestCase), so they switch it on.
            'tools.external_writes' => true,
            'services.shopify.store' => 'test-store.myshopify.com',
            'services.shopify.access_token' => 'test-token',
            'team_sponsorship.bottles' => ['label' => 'Bottles', 'product_id' => 111, 'variant_id' => 1111],
            'team_sponsorship.sample_packs' => ['label' => 'Sample Packs', 'product_id' => 222, 'variant_id' => 2222],
            'team_sponsorship.team_towels' => [
                'label' => 'Team Towels', 'product_id' => 333, 'variant_id' => 3333,
                'per_bottles' => 5, 'max_per_order' => 3,
            ],
            'team_sponsorship.hockey_pucks' => ['label' => 'Hockey Pucks', 'product_id' => 444, 'variant_id' => 4444],
        ]);
    }

    protected function fakeVariantLookups(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/products/111.json' => Http::response([
                'product' => ['variants' => [['id' => 1111, 'inventory_item_id' => 9111]]],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/products/222.json' => Http::response([
                'product' => ['variants' => [['id' => 2222, 'inventory_item_id' => 9222]]],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/products/333.json' => Http::response([
                'product' => ['variants' => [['id' => 3333, 'inventory_item_id' => 9333]]],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/products/444.json' => Http::response([
                'product' => ['variants' => [['id' => 4444, 'inventory_item_id' => 9444]]],
            ]),
        ]);
    }

    public function test_fetch_variant_returns_the_variant(): void
    {
        Http::fake([
            '*/admin/api/2024-01/variants/1111.json' => Http::response([
                'variant' => ['id' => 1111, 'inventory_quantity' => 42],
            ]),
        ]);

        $variant = (new ShopifyClient)->fetchVariant(1111);

        $this->assertSame(42, $variant['inventory_quantity']);
    }

    public function test_fetch_product_variant_finds_the_matching_variant_among_several(): void
    {
        Http::fake([
            '*/admin/api/2024-01/products/111.json' => Http::response([
                'product' => ['variants' => [
                    ['id' => 9999, 'inventory_item_id' => 1],
                    ['id' => 1111, 'inventory_item_id' => 9111],
                ]],
            ]),
        ]);

        $variant = (new ShopifyClient)->fetchProductVariant(111, 1111);

        $this->assertSame(9111, $variant['inventory_item_id']);
    }

    public function test_draft_order_includes_bottles_and_sample_packs_but_no_towels_under_five_bottles(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 555, 'name' => '#D1'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/555/send_invoice.json' => Http::response([], 200),
        ]);

        $result = (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Test Team', 4);

        $this->assertSame(555, $result['draft_order_id']);
        $this->assertTrue($result['email_sent']);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2024-01/draft_orders.json') {
                return true;
            }

            $lineItems = $request->data()['draft_order']['line_items'];

            return count($lineItems) === 2
                && $lineItems[0]['quantity'] === 4
                && $lineItems[1]['quantity'] === 4;
        });
    }

    public function test_draft_order_includes_one_towel_per_five_bottles_capped_at_three(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 556, 'name' => '#D2'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/556/send_invoice.json' => Http::response([], 200),
        ]);

        (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Big Team', 50);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2024-01/draft_orders.json') {
                return true;
            }

            $lineItems = $request->data()['draft_order']['line_items'];

            return count($lineItems) === 3 && $lineItems[2]['quantity'] === 3;
        });
    }

    public function test_draft_order_uses_a_hundred_percent_discount_and_free_shipping(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 557, 'name' => '#D3'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/557/send_invoice.json' => Http::response([], 200),
        ]);

        (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Test Team', 4);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2024-01/draft_orders.json') {
                return true;
            }

            $order = $request->data()['draft_order'];

            return $order['applied_discount']['value'] === '100.0'
                && $order['shipping_line']['price'] === '0.00';
        });
    }

    public function test_draft_order_includes_one_hockey_puck_per_bottle_for_a_hockey_team(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 558, 'name' => '#D4'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/558/send_invoice.json' => Http::response([], 200),
        ]);

        (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Ice Team', 4, 'HOCKEY');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2024-01/draft_orders.json') {
                return true;
            }

            $lineItems = $request->data()['draft_order']['line_items'];
            $puckLine = collect($lineItems)->firstWhere('variant_id', 4444);

            return count($lineItems) === 3 && $puckLine !== null && $puckLine['quantity'] === 4;
        });
    }

    public function test_draft_order_matches_sport_case_insensitively(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 559, 'name' => '#D5'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/559/send_invoice.json' => Http::response([], 200),
        ]);

        (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Ice Team', 4, 'hockey');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2024-01/draft_orders.json') {
                return true;
            }

            return collect($request->data()['draft_order']['line_items'])->contains('variant_id', 4444);
        });
    }

    public function test_draft_order_excludes_hockey_pucks_for_a_non_hockey_team(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 560, 'name' => '#D6'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/560/send_invoice.json' => Http::response([], 200),
        ]);

        (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Hoop Team', 4, 'BASKETBALL');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2024-01/draft_orders.json') {
                return true;
            }

            $lineItems = $request->data()['draft_order']['line_items'];

            return count($lineItems) === 2 && ! collect($lineItems)->contains('variant_id', 4444);
        });
    }

    public function test_draft_order_excludes_hockey_pucks_when_sport_is_null(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 561, 'name' => '#D7'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/561/send_invoice.json' => Http::response([], 200),
        ]);

        (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Unknown Team', 4);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2024-01/draft_orders.json') {
                return true;
            }

            return ! collect($request->data()['draft_order']['line_items'])->contains('variant_id', 4444);
        });
    }

    public function test_a_failed_draft_order_creation_throws(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response(
                ['errors' => 'Something went wrong'],
                422
            ),
        ]);

        $this->expectException(RuntimeException::class);

        (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Test Team', 4);
    }

    public function test_draft_order_result_includes_the_line_item_breakdown(): void
    {
        $this->fakeVariantLookups();
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders.json' => Http::response([
                'draft_order' => ['id' => 562, 'name' => '#D8'],
            ]),
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/562/send_invoice.json' => Http::response([], 200),
        ]);

        $result = (new ShopifyClient)->createSponsorshipDraftOrder('team@example.com', 'Ice Team', 10, 'HOCKEY');

        $this->assertSame([
            ['label' => 'Bottles', 'quantity' => 10],
            ['label' => 'Sample Packs', 'quantity' => 10],
            ['label' => 'Team Towels', 'quantity' => 2],
            ['label' => 'Hockey Pucks', 'quantity' => 10],
        ], $result['line_items']);
    }

    public function test_get_draft_order_returns_the_draft_order(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/999.json' => Http::response([
                'draft_order' => ['id' => 999, 'line_items' => [['variant_id' => 1111, 'quantity' => 5]]],
            ]),
        ]);

        $draftOrder = (new ShopifyClient)->getDraftOrder(999);

        $this->assertSame(999, $draftOrder['id']);
        $this->assertSame(5, $draftOrder['line_items'][0]['quantity']);
    }

    public function test_get_draft_order_throws_on_failure(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/api/2024-01/draft_orders/999.json' => Http::response(['error' => 'Not found'], 404),
        ]);

        $this->expectException(RuntimeException::class);

        (new ShopifyClient)->getDraftOrder(999);
    }

    public function test_product_label_for_variant_id_matches_a_known_product(): void
    {
        $client = new ShopifyClient;

        $this->assertSame('Bottles', $client->productLabelForVariantId(1111));
        $this->assertSame('Hockey Pucks', $client->productLabelForVariantId(4444));
    }

    public function test_product_label_for_variant_id_returns_null_for_an_unknown_variant(): void
    {
        $this->assertNull((new ShopifyClient)->productLabelForVariantId(999999));
    }
}
