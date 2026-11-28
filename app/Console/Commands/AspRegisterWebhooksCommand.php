<?php

namespace App\Console\Commands;

use App\Services\AspTracker\OrderFetcher;
use App\Support\ExternalWrites;
use App\Support\ExternalWritesDisabled;
use Illuminate\Console\Command;

/**
 * One-time (per environment) setup, run by hand after deploy — same
 * reasoning as stockout:register-webhook: it registers real subscriptions
 * against the live store, pointing at a callback URL that must already be
 * serving. Idempotent: skips any topic already subscribed to the same URI.
 */
class AspRegisterWebhooksCommand extends Command
{
    protected $signature = 'asp:register-webhooks {store=CP}';

    protected $description = 'Registers the ASP tracker\'s order/refund webhook subscriptions with Shopify';

    private const LIST_QUERY = <<<'GQL'
        query($topics: [WebhookSubscriptionTopic!]) {
            webhookSubscriptions(first: 100, topics: $topics) {
                edges { node { id topic uri } }
            }
        }
        GQL;

    private const CREATE_MUTATION = <<<'GQL'
        mutation($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
            webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
                webhookSubscription { id uri }
                userErrors { field message }
            }
        }
        GQL;

    public function handle(): int
    {
        $store = strtoupper($this->argument('store'));
        if (config("asp_tracker.stores.{$store}") === null) {
            $this->error("Unknown store {$store}.");

            return self::FAILURE;
        }

        if (blank(config("asp_tracker.stores.{$store}.webhook_secret"))) {
            $this->error("asp_tracker.stores.{$store}.webhook_secret is blank — set it (the app's API secret key) before registering, or every delivered webhook will be rejected with 401.");

            return self::FAILURE;
        }

        $callbackUrl = str_replace('{store}', strtolower($store), config('asp_tracker.webhook_callback_url'));
        $topics = config('asp_tracker.webhook_topics');
        $client = OrderFetcher::client($store);

        $existing = $client->query(self::LIST_QUERY, ['topics' => $topics]);
        if ($existing['errors']) {
            $this->error('Shopify error listing existing subscriptions: '.($existing['errors'][0]['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $registered = [];
        foreach ($existing['data']['webhookSubscriptions']['edges'] ?? [] as $edge) {
            if ($edge['node']['uri'] === $callbackUrl) {
                $registered[$edge['node']['topic']] = $edge['node']['id'];
            }
        }

        $failed = false;
        // Not run in this app until cutover (plan section 5.6).
        if (! ExternalWrites::allowed('asp-tracker')) {
            $this->error((new ExternalWritesDisabled('Shopify webhookSubscriptionCreate'))->getMessage());

            return self::FAILURE;
        }

        foreach ($topics as $topic) {
            if (isset($registered[$topic])) {
                $this->info("{$topic}: already registered ({$registered[$topic]})");

                continue;
            }

            $result = $client->query(self::CREATE_MUTATION, [
                'topic' => $topic,
                'webhookSubscription' => ['uri' => $callbackUrl, 'format' => 'JSON'],
            ]);
            $userErrors = $result['data']['webhookSubscriptionCreate']['userErrors'] ?? [];
            $subscription = $result['data']['webhookSubscriptionCreate']['webhookSubscription'] ?? null;

            if ($result['errors'] || $userErrors !== [] || $subscription === null) {
                $message = $userErrors !== []
                    ? implode('; ', array_map(fn (array $e) => $e['message'], $userErrors))
                    : ($result['errors'][0]['message'] ?? 'webhookSubscriptionCreate returned no subscription and no error.');
                $this->error("{$topic}: FAILED — {$message}");
                $failed = true;

                continue;
            }

            $this->info("{$topic}: registered {$subscription['id']} -> {$subscription['uri']}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
