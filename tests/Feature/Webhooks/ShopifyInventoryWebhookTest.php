<?php

namespace Tests\Feature\Webhooks;

use App\Models\InventorySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShopifyInventoryWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stockout.webhook_secret' => 'test-secret']);
    }

    private function signedPost(string $body): TestResponse
    {
        $hmac = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));

        return $this->call('POST', route('webhooks.shopify.inventory-levels-update'), [], [], [], [
            'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_a_correctly_signed_payload_is_accepted_and_persisted(): void
    {
        $body = json_encode([
            'inventory_item_id' => 10,
            'location_id' => 100,
            'available' => 5,
            'updated_at' => '2026-09-15T12:00:00-04:00',
        ]);

        $response = $this->signedPost($body);

        $response->assertOk();
        $this->assertDatabaseHas('inventory_snapshots', [
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'location_id' => 'gid://shopify/Location/100',
            'state' => 'available',
            'quantity' => 5,
            'source' => 'webhook',
        ]);
    }

    public function test_it_resolves_variant_id_from_the_most_recent_matching_snapshot(): void
    {
        InventorySnapshot::create([
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'location_id' => 'gid://shopify/Location/100',
            'state' => 'available', 'quantity' => 20, 'captured_at' => now()->subHour(), 'source' => 'cron',
        ]);

        $body = json_encode(['inventory_item_id' => 10, 'location_id' => 100, 'available' => 5]);
        $this->signedPost($body)->assertOk();

        $this->assertDatabaseHas('inventory_snapshots', [
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'source' => 'webhook',
        ]);
    }

    public function test_the_variant_comes_from_the_item_map_not_snapshot_history(): void
    {
        DB::table('inventory_item_variants')->insert([
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        InventorySnapshot::create([
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'location_id' => 'gid://shopify/Location/100',
            'state' => 'available', 'quantity' => 20, 'captured_at' => now()->subHour(), 'source' => 'cron',
        ]);

        DB::enableQueryLog();
        $this->signedPost(json_encode(['inventory_item_id' => 10, 'location_id' => 100, 'available' => 5]))->assertOk();
        $snapshotReads = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select') && str_contains($q['query'], 'inventory_snapshots'));

        $this->assertCount(0, $snapshotReads);
        $this->assertDatabaseHas('inventory_snapshots', ['variant_id' => 'gid://shopify/ProductVariant/2', 'source' => 'webhook']);
    }

    public function test_an_unmapped_item_falls_back_to_history_once_and_is_remembered(): void
    {
        InventorySnapshot::create([
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/1',
            'location_id' => 'gid://shopify/Location/100',
            'state' => 'available', 'quantity' => 20, 'captured_at' => now()->subHour(), 'source' => 'cron',
        ]);

        $this->signedPost(json_encode(['inventory_item_id' => 10, 'location_id' => 100, 'available' => 5]))->assertOk();

        $this->assertDatabaseHas('inventory_item_variants', [
            'inventory_item_id' => 'gid://shopify/InventoryItem/10',
            'variant_id' => 'gid://shopify/ProductVariant/1',
        ]);
    }

    public function test_a_brand_new_item_is_recorded_without_a_variant(): void
    {
        $this->signedPost(json_encode(['inventory_item_id' => 99, 'location_id' => 100, 'available' => 5]))->assertOk();

        $this->assertDatabaseHas('inventory_snapshots', ['inventory_item_id' => 'gid://shopify/InventoryItem/99', 'variant_id' => null]);
        $this->assertDatabaseCount('inventory_item_variants', 0);
    }

    public function test_an_incorrect_signature_is_rejected_without_persisting_anything(): void
    {
        $body = json_encode(['inventory_item_id' => 10, 'location_id' => 100, 'available' => 5]);

        $response = $this->call('POST', route('webhooks.shopify.inventory-levels-update'), [], [], [], [
            'HTTP_X-Shopify-Hmac-Sha256' => 'not-the-right-signature',
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);
        $this->assertDatabaseCount('inventory_snapshots', 0);
    }

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $body = json_encode(['inventory_item_id' => 10, 'location_id' => 100, 'available' => 5]);

        $response = $this->call('POST', route('webhooks.shopify.inventory-levels-update'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);
    }

    public function test_a_blank_configured_secret_rejects_every_request_rather_than_skipping_verification(): void
    {
        config(['stockout.webhook_secret' => '']);

        $body = json_encode(['inventory_item_id' => 10, 'location_id' => 100, 'available' => 5]);
        $hmac = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));

        $response = $this->call('POST', route('webhooks.shopify.inventory-levels-update'), [], [], [], [
            'HTTP_X-Shopify-Hmac-Sha256' => $hmac,
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);
        $this->assertDatabaseCount('inventory_snapshots', 0);
    }

    public function test_a_malformed_payload_is_rejected_with_422(): void
    {
        $body = json_encode(['inventory_item_id' => 10]); // missing location_id/available

        $this->signedPost($body)->assertStatus(422);
        $this->assertDatabaseCount('inventory_snapshots', 0);
    }
}
