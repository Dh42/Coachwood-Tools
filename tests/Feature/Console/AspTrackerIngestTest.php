<?php

namespace Tests\Feature\Console;

use App\Models\AspIngestLog;
use App\Services\AspTracker\BulkJsonlReader;
use App\Services\AspTracker\ListPriceResolver;
use App\Services\AspTracker\OrderLineMapper;
use App\Services\AspTracker\SalesLineWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PDOException;
use RuntimeException;
use Tests\Support\AspOrderFixtures;
use Tests\TestCase;

class AspTrackerIngestTest extends TestCase
{
    use AspOrderFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureAspStore();
        Http::preventStrayRequests();
        // Changed in the rebuild: the backfill's bulk operation and the webhook
        // registration go through ExternalWrites, which is off in tests.
        // These tests are about the commands themselves; AspTrackerSafetyTest
        // covers the guard.
        config(['tools.external_writes' => true]);
    }

    private function write(array ...$orders): array
    {
        $mapper = new OrderLineMapper('CP');

        return (new SalesLineWriter)->write(array_map(fn ($o) => $mapper->map($o), $orders));
    }

    private function line(int $id): ?object
    {
        return DB::table('asp_sales_lines')->where('line_item_id', $id)->first();
    }

    public function test_net_comes_from_allocations_with_order_and_line_level_split(): void
    {
        // Real shape from #1285313: Buy-more-save-more (EACH, automatic) plus
        // two Rivo codes (ACROSS) on the same line.
        $this->write(self::order(1, [self::lineItem(11, [
            'originalUnitPriceSet' => self::money(84.99),
            'discountAllocations' => [
                self::allocation(12.74, 'EACH', 'AutomaticDiscountApplication', 'PROTEIN BUY MORE SAVE MORE (EVQKM-0)'),
                self::allocation(18.68, 'ACROSS', 'DiscountCodeApplication', 'CAP-78dc1e893432'),
                self::allocation(1.87, 'ACROSS', 'DiscountCodeApplication', 'CAP-6045e50aaa63'),
            ],
        ])]));

        $line = $this->line(11);
        $this->assertEquals(12.74, $line->line_level_discount);
        $this->assertEquals(20.55, $line->order_level_discount);
        $this->assertEquals(51.70, $line->net_line_total);
        $this->assertEquals(84.99, $line->list_unit_price);
        $this->assertSame('own', $line->list_price_source);
        $this->assertFalse((bool) $line->is_subscription);

        $groups = DB::table('asp_line_discounts')->where('line_item_id', 11)->pluck('label_group')->all();
        $this->assertSame(['PROTEIN BUY MORE SAVE MORE (EVQKM-0)', 'Rivo loyalty', 'Rivo loyalty'], $groups);
    }

    public function test_product_specific_codes_count_even_though_discounted_total_leaves_them_out(): void
    {
        // AMAZON10 (EACH code) — Shopify's discountedTotalSet ignores it; we don't.
        $this->write(self::order(1, [self::lineItem(11, [
            'originalUnitPriceSet' => self::money(59.99),
            'discountAllocations' => [self::allocation(5.99, 'EACH', 'DiscountCodeApplication', 'AMAZON10')],
        ])]));

        $this->assertEquals(54.00, $this->line(11)->net_line_total);
        $this->assertEquals(5.99, $this->line(11)->line_level_discount);
    }

    public function test_shipping_allocations_are_ignored(): void
    {
        $this->write(self::order(1, [self::lineItem(11, [
            'discountAllocations' => [self::allocation(10, 'ACROSS', 'DiscountCodeApplication', 'FREESHIP', 'SHIPPING_LINE')],
        ])]));

        $this->assertEquals(79.99, $this->line(11)->net_line_total);
        $this->assertSame(0, DB::table('asp_line_discounts')->count());
    }

    public function test_refunds_free_items_gift_cards_and_local_dates(): void
    {
        $this->write(self::order(1, [
            self::lineItem(11, ['quantity' => 3, 'originalUnitPriceSet' => self::money(48.50)]),
            self::lineItem(12, ['discountAllocations' => [self::allocation(79.99, 'ACROSS', 'ManualDiscountApplication', 'Claim #9640 - Replacement')]]),
            self::lineItem(13, ['isGiftCard' => true]),
        ], [
            // 02:00 UTC is still the previous day in New York.
            'createdAt' => '2026-10-13T02:00:00Z',
            'refunds' => [self::refund('1', [[11, 1, 48.50]]), ['id' => 'gid://shopify/Refund/2', 'refundLineItems' => []]],
        ]));

        $line = $this->line(11);
        $this->assertSame(1, (int) $line->refunded_qty);
        $this->assertEquals(48.50, $line->refunded_amount);
        $this->assertSame('2026-10-12', substr($line->order_date, 0, 10));
        $this->assertSame('2026-10-12', substr($line->week_start, 0, 10));
        $this->assertTrue((bool) $this->line(12)->is_free_item);
        $this->assertNull($this->line(13));
    }

    public function test_test_orders_are_skipped_and_tax_inclusive_orders_fail_loudly(): void
    {
        $this->write(self::order(1, [self::lineItem(11)], ['test' => true]));
        $this->assertNull($this->line(11));

        $this->expectException(RuntimeException::class);
        $this->write(self::order(2, [self::lineItem(21)], ['taxesIncluded' => true]));
    }

    public function test_rewriting_is_idempotent_and_order_edits_drop_removed_lines(): void
    {
        $order = self::order(1, [self::lineItem(11), self::lineItem(12)]);

        $this->assertSame(2, $this->write($order)['lines_changed']);
        $this->assertSame(0, $this->write($order)['lines_changed']);

        $edited = self::order(1, [self::lineItem(11, ['quantity' => 2])]);
        $this->assertSame(2, $this->write($edited)['lines_changed']);
        $this->assertNull($this->line(12));
        $this->assertSame(2, (int) $this->line(11)->quantity);
    }

    /** Rollup totals per day, rounded, for comparing against a from-scratch rebuild. */
    private function rollupSnapshot(): array
    {
        return DB::table('asp_daily_sales')->orderBy('order_date')->orderBy('variant_id')->orderBy('is_subscription')
            // Changed in the rebuild: was 'is_draft', the column's name before the old app
            // renamed it to is_excluded. SQLite read the unknown name as a plain string,
            // so the test passed without comparing it; MariaDB caught it.
            ->get(['order_date', 'variant_id', 'is_subscription', 'is_free_item', 'is_excluded', 'line_count', 'gross_qty', 'net_qty', 'net_revenue', 'list_total', 'selling_plan_discount', 'full_price_qty'])
            ->map(fn ($r) => array_map(fn ($v) => is_numeric($v) ? round((float) $v, 2) : $v, (array) $r))
            ->all();
    }

    public function test_daily_rollups_follow_every_write_and_match_a_full_rebuild(): void
    {
        $this->write(
            self::order(1, [self::lineItem(11, ['quantity' => 2]), self::lineItem(12)], ['createdAt' => '2026-10-14T16:00:00Z']),
            self::order(2, [self::lineItem(21, ['originalUnitPriceSet' => self::money(60.00), 'discountAllocations' => [self::allocation(10.00, 'ACROSS', 'DiscountCodeApplication', 'CAP-abc123def456')]])], ['createdAt' => '2026-10-15T16:00:00Z']),
            self::order(3, [self::lineItem(31, ['sellingPlan' => ['name' => 'Every 30 days'], 'originalUnitPriceSet' => self::money(55.99)])], ['createdAt' => '2026-10-15T17:00:00Z']),
        );

        $day = fn (string $date) => DB::table('asp_daily_sales')->where('order_date', $date);
        $this->assertSame(3, (int) $day('2026-10-14')->sum('net_qty'));
        $this->assertEquals(50.00, $day('2026-10-15')->where('is_subscription', false)->sum('net_revenue'));
        $this->assertEquals(10.00, DB::table('asp_daily_discounts')->where('label_group', 'Rivo loyalty')->sum('amount'));

        // Resolving the subscription's list price (that day's one-time price,
        // $60) rebuilds its day.
        (new ListPriceResolver)->resolve('CP');
        $this->assertEquals(4.01, $day('2026-10-15')->where('is_subscription', true)->value('selling_plan_discount'));

        // An order edit, a cancellation and a refund all move the rollups.
        $this->write(
            self::order(1, [self::lineItem(11, ['quantity' => 2])], ['createdAt' => '2026-10-14T16:00:00Z', 'refunds' => [self::refund('r1', [[11, 1, 79.99]])]]),
            self::order(2, [self::lineItem(21)], ['createdAt' => '2026-10-15T16:00:00Z', 'cancelledAt' => '2026-10-16T10:00:00Z']),
        );
        $this->assertSame(1, (int) $day('2026-10-14')->sum('net_qty'));
        $this->assertSame(0, DB::table('asp_daily_discounts')->where('label_group', 'Rivo loyalty')->count());
        $this->assertSame(1, $day('2026-10-15')->count());

        $incremental = $this->rollupSnapshot();
        $this->artisan('asp:rebuild-rollups', ['store' => 'CP'])->assertSuccessful();
        $this->assertSame($incremental, $this->rollupSnapshot());

        // A full rebuild also clears days left with no lines at all.
        DB::table('asp_sales_lines')->where('order_id', 3)->delete();
        $this->artisan('asp:rebuild-rollups', ['store' => 'CP'])->assertSuccessful();
        $this->assertSame(0, $day('2026-10-15')->count());
    }

    public function test_subscription_list_price_comes_from_one_time_sales_of_the_same_variant(): void
    {
        $sub = fn (int $id, string $date, ?string $variant = null) => self::order($id, [self::lineItem($id * 10, [
            'quantity' => 2,
            'sellingPlan' => ['name' => 'Delivery every 30 days'],
            'originalUnitPriceSet' => self::money(55.99),
            'variant' => ['id' => 'gid://shopify/ProductVariant/'.($variant ?? '900'), 'price' => '81.00'],
        ])], ['createdAt' => $date]);

        $this->write(
            self::order(1, [self::lineItem(10, ['originalUnitPriceSet' => self::money(79.99)])], ['createdAt' => '2026-09-01T16:00:00Z']),
            self::order(2, [self::lineItem(20, ['originalUnitPriceSet' => self::money(74.99)])], ['createdAt' => '2026-10-01T16:00:00Z']),
            $sub(3, '2026-09-20T16:00:00Z'),          // nearest earlier one-time day: 09-01 → 79.99
            $sub(4, '2026-08-20T16:00:00Z'),          // nothing earlier → nearest later: 09-01
            $sub(5, '2026-10-20T16:00:00Z', '901'),   // no one-time sales of this variant → its current price
        );

        $this->assertSame(3, (new ListPriceResolver)->resolve('CP'));

        $this->assertEquals(79.99, $this->line(30)->list_unit_price);
        $this->assertSame('prior', $this->line(30)->list_price_source);
        $this->assertEquals(48.00, $this->line(30)->selling_plan_discount);
        $this->assertSame('after', $this->line(40)->list_price_source);
        $this->assertSame('variant', $this->line(50)->list_price_source);
        $this->assertEquals(81.00, $this->line(50)->list_unit_price);

        // A re-fetch of an unchanged subscription order keeps its resolved price.
        $this->write($sub(3, '2026-09-20T16:00:00Z'));
        $this->assertEquals(79.99, $this->line(30)->list_unit_price);
    }

    public function test_bulk_jsonl_is_reassembled_into_orders(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asp');
        $lines = [
            ['id' => 'gid://shopify/Order/1', 'name' => '#1'],
            ['id' => 'gid://shopify/LineItem/11', 'quantity' => 1, '__parentId' => 'gid://shopify/Order/1'],
            ['id' => 'gid://shopify/Order/2', 'name' => '#2'],
            ['id' => 'gid://shopify/LineItem/21', 'quantity' => 1, '__parentId' => 'gid://shopify/Order/2'],
            // Late child of order 1 — recorded for a re-fetch, not dropped.
            ['id' => 'gid://shopify/LineItem/12', 'quantity' => 1, '__parentId' => 'gid://shopify/Order/1'],
        ];
        file_put_contents($path, implode("\n", array_map('json_encode', $lines))."\n");

        $reader = new BulkJsonlReader;
        $orders = iterator_to_array($reader->orders($path), false);
        unlink($path);

        $this->assertCount(2, $orders);
        $this->assertCount(1, $orders[0]['lineItems']);
        $this->assertArrayNotHasKey('__parentId', $orders[0]['lineItems'][0]);
        // No refunds key: tells OrderLineMapper to estimate from currentQuantity.
        $this->assertArrayNotHasKey('refunds', $orders[0]);
        $this->assertSame([1 => true], $reader->orphanOrderIds);
    }

    private static function bulkOrder(int $id, array $lines, array $overrides = []): array
    {
        $order = self::order($id, [], $overrides);
        unset($order['lineItems'], $order['refunds']);

        return [json_encode($order), ...array_map(fn ($l) => json_encode($l + ['__parentId' => "gid://shopify/Order/{$id}"]), $lines)];
    }

    /** @param  int  $repolls  extra COMPLETED polls after the first, for runs that import again */
    private function fakeBulkRun(string $jsonl, int $repolls = 0): void
    {
        $node = fn (string $status, ?string $url) => ['data' => ['node' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => $status, 'errorCode' => null, 'objectCount' => '3', 'fileSize' => null, 'url' => $url, 'partialDataUrl' => null]]];

        $shopify = Http::sequence()
            ->push(['data' => ['shop' => ['taxesIncluded' => false, 'currencyCode' => 'CAD']]])
            ->push(['data' => ['bulkOperationRunQuery' => ['bulkOperation' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'CREATED'], 'userErrors' => []]]])
            ->push($node('RUNNING', null))
            ->push($node('COMPLETED', 'https://storage.example/bulk.jsonl'));
        for ($i = 0; $i < $repolls; $i++) {
            $shopify->push($node('COMPLETED', 'https://storage.example/bulk.jsonl'));
        }

        Http::fake([
            'cp-test.myshopify.com/*' => $shopify,
            // A fresh response per download: a re-import reads the file again.
            'storage.example/*' => fn () => Http::response($jsonl),
        ]);
    }

    public function test_backfill_submits_polls_then_imports(): void
    {
        $this->fakeBulkRun(implode("\n", [
            ...self::bulkOrder(1, [self::lineItem(11, ['currentQuantity' => 1])]),
            // 3 bought, 1 refunded: estimated from currentQuantity, queued for exact re-fetch.
            ...self::bulkOrder(2, [self::lineItem(21, ['quantity' => 3, 'currentQuantity' => 2, 'originalUnitPriceSet' => self::money(50.00)])]),
        ])."\n");

        $this->artisan('asp:backfill CP')->expectsOutputToContain('[submitted]')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r['query'] ?? '', 'bulkOperationRunQuery') && str_contains($r['variables']['query'] ?? '', "created_at:>='2024-01-01'"));

        $this->artisan('asp:backfill CP')->expectsOutputToContain('[running]')->assertSuccessful();
        $this->artisan('asp:backfill CP')->expectsOutputToContain('[completed] Imported 2 orders')->assertSuccessful();

        $this->assertNotNull($this->line(11));
        $this->assertSame(1, (int) $this->line(21)->refunded_qty);
        $this->assertEquals(50.00, $this->line(21)->refunded_amount);
        $this->assertSame([2], DB::table('asp_pending_orders')->pluck('order_id')->map(fn ($v) => (int) $v)->all());

        $log = AspIngestLog::where('kind', 'backfill')->first();
        $this->assertSame('completed', $log->status);
        $this->assertSame(2, $log->orders_touched);

        // Done means done — no new bulk operation without --fresh.
        $this->artisan('asp:backfill CP')->expectsOutputToContain('already completed')->assertSuccessful();
    }

    public function test_an_import_that_loses_a_deadlock_retries_on_the_next_run_instead_of_failing(): void
    {
        // The run after the deadlock re-polls the (still completed) operation.
        $this->fakeBulkRun(implode("\n", self::bulkOrder(1, [self::lineItem(11)]))."\n", repolls: 1);

        // First write loses a deadlock to another ASP job (MariaDB 1213, as seen live).
        $this->app->instance(SalesLineWriter::class, new class extends SalesLineWriter
        {
            private bool $deadlocked = false;

            public function write(array $mappedOrders): array
            {
                if (! $this->deadlocked && $mappedOrders !== []) {
                    $this->deadlocked = true;
                    throw new QueryException('mysql', 'insert into asp_daily_sales …', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
                }

                return parent::write($mappedOrders);
            }
        });

        $this->artisan('asp:backfill CP');
        $this->artisan('asp:backfill CP');
        $this->artisan('asp:backfill CP')->expectsOutputToContain('[retrying]')->assertSuccessful();
        $this->assertSame('running', AspIngestLog::where('kind', 'backfill')->first()->status);
        $this->assertNull($this->line(11));

        $this->artisan('asp:backfill CP')->expectsOutputToContain('[completed] Imported 1 orders')->assertSuccessful();
        $this->assertNotNull($this->line(11));
        $this->assertStringContainsString('Import retried after a database deadlock', AspIngestLog::where('kind', 'backfill')->first()->notes);
    }

    public function test_a_backfill_rerun_keeps_exact_refunds_and_changes_nothing(): void
    {
        // Already stored from an exact re-fetch: refund subtotal $49.99, not the $50.00 estimate.
        $this->write(self::order(2, [self::lineItem(21, ['quantity' => 3, 'currentQuantity' => 2, 'originalUnitPriceSet' => self::money(50.00)])], [
            'refunds' => [self::refund('1', [[21, 1, 49.99]])],
        ]));

        $this->fakeBulkRun(implode("\n", self::bulkOrder(2, [self::lineItem(21, ['quantity' => 3, 'currentQuantity' => 2, 'originalUnitPriceSet' => self::money(50.00)])]))."\n");

        $this->artisan('asp:backfill CP --fresh');
        $this->artisan('asp:backfill CP');
        $this->artisan('asp:backfill CP')->expectsOutputToContain('(0 lines changed)')->assertSuccessful();

        $this->assertEquals(49.99, $this->line(21)->refunded_amount);
        $this->assertSame(0, DB::table('asp_pending_orders')->count());
    }

    public function test_backfill_refuses_a_tax_inclusive_store(): void
    {
        Http::fake(['cp-test.myshopify.com/*' => Http::response(['data' => ['shop' => ['taxesIncluded' => true, 'currencyCode' => 'CAD']]])]);

        $this->expectException(RuntimeException::class);
        $this->artisan('asp:backfill CP');
    }

    public function test_process_pending_refetches_queued_orders_and_parks_repeated_failures(): void
    {
        DB::table('asp_pending_orders')->insert([
            ['store' => 'CP', 'order_id' => 1, 'reason' => 'webhook:orders/create', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['store' => 'CP', 'order_id' => 2, 'reason' => 'webhook:orders/create', 'attempts' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake(['cp-test.myshopify.com/*' => function ($request) {
            return $request['variables']['id'] === 'gid://shopify/Order/1'
                ? Http::response(self::graphQlOrderResponse(self::order(1, [self::lineItem(11)])))
                : Http::response(['errors' => [['message' => 'Internal error']]]);
        }]);

        $this->artisan('asp:process-pending CP')->assertFailed();

        $this->assertNotNull($this->line(11));
        $this->assertDatabaseMissing('asp_pending_orders', ['order_id' => 1]);
        $this->assertDatabaseHas('asp_pending_orders', ['order_id' => 2, 'attempts' => 5]);

        // Parked: not retried again.
        $this->artisan('asp:process-pending CP')->expectsOutput('Nothing queued.')->assertSuccessful();
    }

    public function test_process_pending_does_not_fail_the_run_for_an_order_that_will_be_retried(): void
    {
        Sleep::fake();
        DB::table('asp_pending_orders')->insert([
            ['store' => 'CP', 'order_id' => 1, 'reason' => 'catch-up', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['store' => 'CP', 'order_id' => 2, 'reason' => 'catch-up', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake(['cp-test.myshopify.com/*' => function ($request) {
            return $request['variables']['id'] === 'gid://shopify/Order/1'
                ? Http::response(self::graphQlOrderResponse(self::order(1, [self::lineItem(11)])))
                : Http::failedConnection('cURL error 28: Failed to connect to cp-test.myshopify.com port 443 after 10001 ms: Timeout was reached')($request);
        }]);

        $this->artisan('asp:process-pending CP')
            ->expectsOutputToContain('Still queued, will retry next run')
            ->assertSuccessful();

        $this->assertDatabaseMissing('asp_pending_orders', ['order_id' => 1]);
        $this->assertDatabaseHas('asp_pending_orders', ['order_id' => 2, 'attempts' => 1]);
    }

    public function test_deleted_orders_are_removed_on_refetch(): void
    {
        $this->write(self::order(1, [self::lineItem(11)]));
        DB::table('asp_pending_orders')->insert(['store' => 'CP', 'order_id' => 1, 'reason' => 'catch-up', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['cp-test.myshopify.com/*' => Http::response(self::graphQlOrderResponse(null))]);

        $this->artisan('asp:process-pending CP')->assertSuccessful();

        $this->assertNull($this->line(11));
    }

    public function test_process_pending_prunes_old_pending_logs_in_batches_and_keeps_everything_else(): void
    {
        $old = now()->subDays(31);
        $rows = array_fill(0, 1500, ['store' => 'CP', 'kind' => 'pending', 'status' => 'completed', 'started_at' => $old, 'created_at' => $old, 'updated_at' => $old]);
        foreach (array_chunk($rows, 500) as $chunk) {
            AspIngestLog::insert($chunk);
        }
        AspIngestLog::create(['store' => 'CP', 'kind' => 'pending', 'status' => 'completed', 'started_at' => now()->subDays(29)]);
        AspIngestLog::create(['store' => 'CP', 'kind' => 'backfill', 'status' => 'completed', 'started_at' => $old]);

        $this->artisan('asp:process-pending CP')->expectsOutputToContain('Nothing queued')->assertSuccessful();

        $this->assertSame(['backfill', 'pending'], AspIngestLog::orderBy('kind')->pluck('kind')->all());
    }

    public function test_catch_up_queues_recently_updated_orders(): void
    {
        Http::fake(['cp-test.myshopify.com/*' => Http::sequence()
            ->push(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1'], 'nodes' => [['id' => 'gid://shopify/Order/1']]]]])
            ->push(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => [['id' => 'gid://shopify/Order/2']]]]]),
        ]);

        $this->artisan('asp:catch-up CP')->expectsOutputToContain('Queued 2 orders')->assertSuccessful();

        $this->assertSame([1, 2], DB::table('asp_pending_orders')->orderBy('order_id')->pluck('order_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_register_webhooks_refuses_without_a_secret_and_skips_existing_topics(): void
    {
        config(['asp_tracker.stores.CP.webhook_secret' => null]);
        $this->artisan('asp:register-webhooks CP')->assertFailed();

        config(['asp_tracker.stores.CP.webhook_secret' => 'asp-secret', 'asp_tracker.webhook_callback_url' => 'https://tools.example/webhooks/shopify/asp/{store}']);
        Http::fake(['cp-test.myshopify.com/*' => function ($request) {
            if (str_contains($request['query'], 'webhookSubscriptions(')) {
                return Http::response(['data' => ['webhookSubscriptions' => ['edges' => [
                    ['node' => ['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'ORDERS_CREATE', 'uri' => 'https://tools.example/webhooks/shopify/asp/cp']],
                ]]]]);
            }

            return Http::response(['data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/9', 'uri' => 'https://tools.example/webhooks/shopify/asp/cp'], 'userErrors' => []]]]);
        }]);

        $this->artisan('asp:register-webhooks CP')->expectsOutputToContain('ORDERS_CREATE: already registered')->assertSuccessful();
        Http::assertSentCount(4); // 1 list + 3 creates
    }
}
