<?php

namespace App\Services\TeamSponsorship;

use App\Services\Klaviyo\KlaviyoClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fires "Claimed Sponsorship Package" on BioSteel's own Klaviyo account
 * once a sponsorship draft order has actually been created in Shopify —
 * called from TeamSponsorshipController::storeOrder() right after that
 * order (and its line items) are persisted locally. Always brand 'BS' —
 * unlike the Combined Shipping Refund tool, Team Sponsorship is
 * BioSteel-only, no brand selector exists for it.
 */
class KlaviyoSponsorshipNotifier
{
    public function __construct(protected KlaviyoClient $client) {}

    /** @param  array{draft_order_id?: mixed, order_name?: ?string, admin_url?: string, line_items?: array}  $order  ShopifyClient::createSponsorshipDraftOrder()'s own return shape */
    public function notifyClaimed(string $email, string $teamName, ?string $sport, array $order): void
    {
        $properties = [
            'team_name' => $teamName,
            'sport' => $sport,
            'order_name' => $order['order_name'] ?? null,
            'order_id' => $order['draft_order_id'] ?? null,
            'admin_url' => $order['admin_url'] ?? null,
            'line_items' => $order['line_items'] ?? [],
        ];

        // Keyed on the real Shopify draft order id, not a fresh value per
        // call — so a genuine retry of the same already-notified order
        // dedupes on Klaviyo's side rather than double-firing the event.
        $uniqueId = isset($order['draft_order_id'])
            ? 'team-sponsorship-claimed-'.$order['draft_order_id']
            : null;

        try {
            $this->client->sendEvent(config('klaviyo.events.claimed_sponsorship_package'), $email, $properties, $uniqueId);
        } catch (Throwable $e) {
            // Never let a Klaviyo problem surface as a failure of order
            // creation itself — by the time this runs, the Shopify draft
            // order already exists and its invoice email has already been
            // sent. Logged only.
            Log::error('KlaviyoSponsorshipNotifier threw', ['email' => $email, 'team' => $teamName, 'message' => $e->getMessage()]);
        }
    }
}
