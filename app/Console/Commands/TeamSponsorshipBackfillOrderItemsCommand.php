<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-time backfill: recovers the product/quantity breakdown for every
 * sponsorship order that predates order_items (created before
 * TeamSponsorshipController::storeOrder() started persisting line items),
 * by re-fetching each order's draft order from Shopify directly. Not
 * scheduled — run once manually (`php artisan team-sponsorship:backfill-order-items`).
 *
 * Idempotent: only processes orders with no items yet, so it's safe to
 * re-run after a partial failure or interruption without double-writing.
 */
class TeamSponsorshipBackfillOrderItemsCommand extends Command
{
    protected $signature = 'team-sponsorship:backfill-order-items {--dry-run}';

    protected $description = "Recovers the product/quantity breakdown for historical sponsorship orders from Shopify's draft order API";

    public function handle(ShopifyClient $client): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $orders = Order::whereDoesntHave('items')->get();

        if ($orders->isEmpty()) {
            $this->info('Every order already has its line items — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Backfilling %d order(s)%s...', $orders->count(), $dryRun ? ' (dry-run — nothing will be written)' : ''));

        $processed = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $draftOrder = $client->getDraftOrder($order->shopify_order_id);
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Order #{$order->id} (shopify_order_id {$order->shopify_order_id}): {$e->getMessage()}");
                $this->pace();

                continue;
            }

            foreach ($draftOrder['line_items'] ?? [] as $lineItem) {
                $label = $client->productLabelForVariantId((int) ($lineItem['variant_id'] ?? 0)) ?? ($lineItem['title'] ?? 'Other');

                if ($dryRun) {
                    $this->line("  Order #{$order->id}: {$label} x {$lineItem['quantity']}");

                    continue;
                }

                $order->items()->create([
                    'product_label' => $label,
                    'quantity' => $lineItem['quantity'],
                ]);
            }

            $processed++;
            $this->pace();
        }

        $this->info("Backfill complete: {$processed} processed, {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * ~2 req/sec, Shopify's documented REST rate limit for a standard
     * store. Skipped under the test suite so a multi-order test doesn't
     * take multiple real seconds — Http::fake() makes actual network
     * pacing meaningless there anyway.
     */
    protected function pace(): void
    {
        if (! app()->runningUnitTests()) {
            usleep(500000);
        }
    }
}
