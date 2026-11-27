<?php

namespace Tests\Feature\Console;

use App\Models\AspIngestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AspOrderFixtures;
use Tests\TestCase;

/**
 * ASP Tracker safeguards added in the rebuild (plan section 5.6): the two
 * Shopify writes, bulkOperationRunQuery and webhookSubscriptionCreate, go
 * through ExternalWrites and don't run here before cutover.
 */
class AspTrackerSafetyTest extends TestCase
{
    use AspOrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureAspStore();
        config(['tools.external_writes' => false]);
    }

    public function test_the_backfill_starts_no_bulk_operation_while_external_writes_are_off(): void
    {
        Http::fake();

        $this->artisan('asp:backfill CP')
            ->expectsOutputToContain('External writes are disabled')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, AspIngestLog::count());
    }

    public function test_registering_webhooks_creates_nothing_while_external_writes_are_off(): void
    {
        config(['asp_tracker.stores.CP.webhook_secret' => 'asp-secret', 'asp_tracker.webhook_callback_url' => 'https://tools.example/webhooks/shopify/asp/{store}']);
        Http::fake(['cp-test.myshopify.com/*' => Http::response(['data' => ['webhookSubscriptions' => ['edges' => []]]])]);

        $this->artisan('asp:register-webhooks CP')
            ->expectsOutputToContain('External writes are disabled')
            ->assertFailed();

        Http::assertNotSent(fn ($request) => str_contains($request['query'] ?? '', 'webhookSubscriptionCreate'));
    }

    /** Moved here from StockoutPruneSnapshotsCommandTest: the 2026-11-16 incident index. */
    public function test_the_incident_index_on_the_ingest_log_exists_after_migrating(): void
    {
        $this->assertTrue(Schema::hasIndex('asp_ingest_log', ['kind', 'started_at']));
    }
}
