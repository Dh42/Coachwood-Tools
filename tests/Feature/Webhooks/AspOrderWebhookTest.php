<?php

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AspOrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['asp_tracker.stores.CP.webhook_secret' => 'asp-secret', 'asp_tracker.stores.BS.webhook_secret' => 'bs-secret', 'stockout.webhook_secret' => 'stockout-secret']);
    }

    private function deliver(string $topic, array $payload, string $secret = 'asp-secret', string $store = 'cp'): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', "/webhooks/shopify/asp/{$store}", [], [], [], [
            'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
            'HTTP_X-Shopify-Topic' => $topic,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_order_webhooks_queue_the_order_id(): void
    {
        $this->deliver('orders/updated', ['id' => 6100000000001, 'name' => '#1'])->assertOk();
        $this->deliver('orders/updated', ['id' => 6100000000001, 'name' => '#1'])->assertOk();

        $this->assertDatabaseCount('asp_pending_orders', 1);
        $this->assertDatabaseHas('asp_pending_orders', ['store' => 'CP', 'order_id' => 6100000000001, 'reason' => 'webhook:orders/updated']);
    }

    public function test_each_store_has_its_own_receiver_and_secret(): void
    {
        $this->deliver('orders/create', ['id' => 7100000000001], 'bs-secret', 'bs')->assertOk();
        $this->deliver('orders/create', ['id' => 7100000000002], 'asp-secret', 'bs')->assertUnauthorized();

        $this->assertDatabaseHas('asp_pending_orders', ['store' => 'BS', 'order_id' => 7100000000001]);
        $this->assertDatabaseCount('asp_pending_orders', 1);
    }

    public function test_refund_webhooks_queue_the_refunds_order(): void
    {
        $this->deliver('refunds/create', ['id' => 999, 'order_id' => 6100000000002])->assertOk();

        $this->assertDatabaseHas('asp_pending_orders', ['order_id' => 6100000000002]);
        $this->assertDatabaseMissing('asp_pending_orders', ['order_id' => 999]);
    }

    public function test_it_rejects_a_bad_signature_another_apps_secret_and_a_missing_secret(): void
    {
        $this->deliver('orders/create', ['id' => 1], 'wrong')->assertUnauthorized();
        $this->deliver('orders/create', ['id' => 1], 'stockout-secret')->assertUnauthorized();

        config(['asp_tracker.stores.CP.webhook_secret' => null]);
        $this->deliver('orders/create', ['id' => 1])->assertUnauthorized();

        $this->assertDatabaseCount('asp_pending_orders', 0);
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $this->deliver('orders/create', ['name' => '#1'])->assertStatus(422);
    }
}
