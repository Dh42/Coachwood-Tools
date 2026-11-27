<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockoutRegisterWebhookCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stockout.shopify.store' => 'shopify-test.myshopify.com',
            'stockout.shopify.access_token' => 'test-token',
            'stockout.api_version' => '2026-07',
            'stockout.webhook_secret' => 'test-secret',
            'stockout.webhook_callback_url' => 'https://tools.biosteel.com/webhooks/shopify/inventory-levels-update',
            // Changed in the rebuild: the create goes through ExternalWrites,
            // which is off in tests. These tests are about the command itself.
            'tools.external_writes' => true,
        ]);
    }

    public function test_it_refuses_to_register_without_a_configured_webhook_secret(): void
    {
        config(['stockout.webhook_secret' => '']);

        Http::fake(); // any call fails the test

        $this->artisan('stockout:register-webhook')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_it_creates_a_subscription_when_none_exists(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push(['data' => ['webhookSubscriptions' => ['edges' => []]]])
                ->push(['data' => ['webhookSubscriptionCreate' => [
                    'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1', 'uri' => 'https://tools.biosteel.com/webhooks/shopify/inventory-levels-update'],
                    'userErrors' => [],
                ]]]),
        ]);

        $this->artisan('stockout:register-webhook')->assertExitCode(0);
        Http::assertSentCount(2);
    }

    public function test_it_skips_creating_a_duplicate_when_already_registered(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::response(['data' => ['webhookSubscriptions' => ['edges' => [
                ['node' => ['id' => 'gid://shopify/WebhookSubscription/1', 'uri' => 'https://tools.biosteel.com/webhooks/shopify/inventory-levels-update']],
            ]]]]),
        ]);

        $this->artisan('stockout:register-webhook')->assertExitCode(0);
        Http::assertSentCount(1); // list only, never calls create
    }

    public function test_shopify_user_errors_on_create_fail_the_command(): void
    {
        Http::fake([
            'shopify-test.myshopify.com/*' => Http::sequence()
                ->push(['data' => ['webhookSubscriptions' => ['edges' => []]]])
                ->push(['data' => ['webhookSubscriptionCreate' => [
                    'webhookSubscription' => null,
                    'userErrors' => [['field' => ['uri'], 'message' => 'is invalid']],
                ]]]),
        ]);

        $this->artisan('stockout:register-webhook')->assertExitCode(1);
    }
}
