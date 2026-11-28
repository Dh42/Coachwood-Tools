<?php

namespace App\Console\Commands;

use App\Services\Stockout\ShopifyGraphQlClient;
use App\Support\ExternalWrites;
use App\Support\ExternalWritesDisabled;
use Illuminate\Console\Command;

/**
 * One-time (per environment) setup, run manually after deploy — not part of
 * the automatic migrate/deploy pipeline, since it registers a real webhook
 * subscription against the live Shopify store and needs a publicly
 * reachable callback URL to already be serving (i.e. this code must be
 * deployed first). Idempotent: checks for an existing subscription with the
 * same topic + uri before creating one, safe to re-run.
 *
 * Mutation shape (topic, webhookSubscription: { uri, format }) confirmed
 * live (2026-09-15) via schema introspection against the real BioSteel
 * store — not fired for real yet, since doing so from local dev would
 * register a subscription pointing at whatever APP_URL resolves to here,
 * not production.
 */
class StockoutRegisterWebhookCommand extends Command
{
    protected $signature = 'stockout:register-webhook';

    protected $description = 'Registers the inventory_levels/update webhook subscription with Shopify, if not already registered';

    private const LIST_QUERY = <<<'GQL'
        query {
            webhookSubscriptions(first: 25, topics: [INVENTORY_LEVELS_UPDATE]) {
                edges { node { id uri } }
            }
        }
        GQL;

    private const CREATE_MUTATION = <<<'GQL'
        mutation($webhookSubscription: WebhookSubscriptionInput!) {
            webhookSubscriptionCreate(topic: INVENTORY_LEVELS_UPDATE, webhookSubscription: $webhookSubscription) {
                webhookSubscription { id uri }
                userErrors { field message }
            }
        }
        GQL;

    public function handle(ShopifyGraphQlClient $client): int
    {
        $callbackUrl = config('stockout.webhook_callback_url');

        if (blank(config('stockout.webhook_secret'))) {
            $this->error('stockout.webhook_secret (STOCKOUT_SHOPIFY_CLIENT_SECRET) is blank — set it before registering, or every delivered webhook will be rejected with 401.');

            return self::FAILURE;
        }

        $existing = $client->query(self::LIST_QUERY);
        if ($existing['errors']) {
            $this->error('Shopify error listing existing subscriptions: '.($existing['errors'][0]['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        foreach ($existing['data']['webhookSubscriptions']['edges'] ?? [] as $edge) {
            if ($edge['node']['uri'] === $callbackUrl) {
                $this->info("Already registered: {$edge['node']['id']} -> {$callbackUrl}");

                return self::SUCCESS;
            }
        }

        // Not run in this app until cutover (plan section 5.4): no webhook
        // points here during shadow running.
        if (! ExternalWrites::allowed('lost-sales')) {
            $this->error((new ExternalWritesDisabled('Shopify webhookSubscriptionCreate'))->getMessage());

            return self::FAILURE;
        }

        $result = $client->query(self::CREATE_MUTATION, [
            'webhookSubscription' => ['uri' => $callbackUrl, 'format' => 'JSON'],
        ]);

        $userErrors = $result['data']['webhookSubscriptionCreate']['userErrors'] ?? [];
        $subscription = $result['data']['webhookSubscriptionCreate']['webhookSubscription'] ?? null;

        if ($result['errors'] || $userErrors !== [] || $subscription === null) {
            $message = $userErrors !== []
                ? implode('; ', array_map(fn (array $e) => $e['message'], $userErrors))
                : ($result['errors'][0]['message'] ?? 'webhookSubscriptionCreate returned no subscription and no error.');
            $this->error("FAILED: {$message}");

            return self::FAILURE;
        }

        $this->info("Registered: {$subscription['id']} -> {$subscription['uri']}");

        return self::SUCCESS;
    }
}
