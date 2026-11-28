<?php

namespace App\Services\Shopify;

use App\Support\ExternalWrites;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyClient
{
    protected string $store;

    protected string $accessToken;

    public function __construct()
    {
        $this->store = (string) config('services.shopify.store');
        $this->accessToken = (string) config('services.shopify.access_token');
    }

    public function fetchVariant(int $variantId): array
    {
        $response = $this->get("/admin/api/2024-01/variants/{$variantId}.json");

        if ($response->failed()) {
            throw new RuntimeException("Shopify API error (HTTP {$response->status()}) while fetching variant {$variantId}.");
        }

        $data = $response->json();

        if (! isset($data['variant'])) {
            throw new RuntimeException("Unexpected Shopify response for variant {$variantId}.");
        }

        return $data['variant'];
    }

    public function fetchProductVariant(int $productId, int $variantId): array
    {
        $response = $this->get("/admin/api/2024-01/products/{$productId}.json");

        if ($response->failed()) {
            throw new RuntimeException("Unable to fetch product {$productId}. Shopify API returned HTTP {$response->status()}.");
        }

        $data = $response->json();

        if (! isset($data['product']['variants'])) {
            throw new RuntimeException("Product {$productId} does not contain any variants.");
        }

        foreach ($data['product']['variants'] as $variant) {
            if (($variant['id'] ?? null) == $variantId) {
                return $variant;
            }
        }

        throw new RuntimeException("Variant ID {$variantId} not found in product {$productId}.");
    }

    /**
     * Builds and sends the same draft order the original standalone tool
     * created: bottles + sample packs at the team's bottle count, one team
     * towel per 5 bottles (capped), a 100% "Sponsorship" discount, free
     * shipping, then sends the invoice email. Hockey teams (per the team's
     * BioSteel sportType) also get one hockey puck per bottle.
     *
     * Creates a real draft order and emails the coach, so it refuses
     * (ExternalWritesDisabled) before any Shopify call while external
     * writes are off.
     */
    public function createSponsorshipDraftOrder(string $email, string $teamName, int $bottleQuantity, ?string $sport = null): array
    {
        ExternalWrites::ensureAllowed('Shopify draft order and invoice email');

        $lineItemConfigs = $this->sponsorshipLineItemConfigs($bottleQuantity, $sport);

        $lineItems = [];

        foreach ($lineItemConfigs as $config) {
            $variant = $this->fetchProductVariant($config['product']['product_id'], $config['product']['variant_id']);

            $lineItem = [
                'variant_id' => $config['product']['variant_id'],
                'quantity' => $config['quantity'],
            ];

            if (! empty($variant['inventory_item_id'])) {
                $lineItem['inventory_item_id'] = $variant['inventory_item_id'];
            }

            $lineItems[] = $lineItem;
        }

        $teamTag = $this->truncateTag("team: {$teamName}");

        $response = $this->post('/admin/api/2024-01/draft_orders.json', [
            'draft_order' => [
                'line_items' => $lineItems,
                'customer' => ['email' => $email],
                'note' => "Team: {$teamName}",
                'tags' => "TeamSponsorship,{$teamTag}",
                'use_customer_default_address' => true,
                'applied_discount' => [
                    'description' => 'Sponsorship',
                    'value_type' => 'percentage',
                    'value' => '100.0',
                    'amount' => null,
                ],
                'shipping_line' => [
                    'title' => 'Free Shipping',
                    'price' => '0.00',
                ],
                'shipping_address' => [
                    'first_name' => '',
                    'last_name' => '',
                    'address1' => '',
                    'city' => '',
                    'province' => '',
                    'country' => 'US',
                    'zip' => '',
                ],
            ],
        ]);

        $result = $response->json();

        if ($response->failed() || ! isset($result['draft_order']['id'])) {
            $error = $result['errors'] ?? $result['error'] ?? 'Unknown error';

            throw new RuntimeException(
                'Shopify API Error (HTTP '.$response->status().'): '.(is_array($error) ? json_encode($error) : $error)
            );
        }

        $draftOrderId = $result['draft_order']['id'];

        $invoiceResponse = $this->post("/admin/api/2024-01/draft_orders/{$draftOrderId}/send_invoice.json", [
            'draft_order_invoice' => [
                'to' => $email,
                'subject' => 'Claim your Free BioSteel Sponsorship Package',
                'custom_message' => "Hello,\n\nYour teams Sponsorship Package for {$teamName} is ready. Please complete your order using the link below.\n\nThank you!",
            ],
        ]);

        return [
            'draft_order_id' => $draftOrderId,
            'order_name' => $result['draft_order']['name'] ?? null,
            'admin_url' => "https://{$this->store}/admin/draft_orders/{$draftOrderId}",
            'email_sent' => $invoiceResponse->successful(),
            'line_items' => array_map(fn (array $config) => [
                'label' => $config['product']['label'],
                'quantity' => $config['quantity'],
            ], $lineItemConfigs),
        ];
    }

    /**
     * What a sponsorship order would contain, as label and quantity, without
     * calling Shopify: shown when writes are off, so the page still says
     * what would have been sent.
     *
     * @return list<array{label: string, quantity: int}>
     */
    public function sponsorshipLineItems(int $bottleQuantity, ?string $sport): array
    {
        return array_map(fn (array $config) => [
            'label' => $config['product']['label'],
            'quantity' => $config['quantity'],
        ], $this->sponsorshipLineItemConfigs($bottleQuantity, $sport));
    }

    /**
     * The product/quantity breakdown for a sponsorship order — bottles +
     * sample packs at the team's bottle count, one team towel per 5 bottles
     * (capped), one hockey puck per bottle for hockey teams. Shared between
     * createSponsorshipDraftOrder()'s Shopify payload and its own return
     * value (so callers can persist what was actually sent, per product).
     *
     * @return array<int, array{product: array, quantity: int}>
     */
    protected function sponsorshipLineItemConfigs(int $bottleQuantity, ?string $sport): array
    {
        $configs = [
            ['product' => config('team_sponsorship.bottles'), 'quantity' => $bottleQuantity],
            ['product' => config('team_sponsorship.sample_packs'), 'quantity' => $bottleQuantity],
        ];

        $towels = config('team_sponsorship.team_towels');
        $towelQuantity = min(intdiv($bottleQuantity, $towels['per_bottles']), $towels['max_per_order']);

        if ($towelQuantity > 0) {
            $configs[] = ['product' => $towels, 'quantity' => $towelQuantity];
        }

        if (mb_strtoupper(trim((string) $sport)) === 'HOCKEY') {
            $configs[] = ['product' => config('team_sponsorship.hockey_pucks'), 'quantity' => $bottleQuantity];
        }

        return $configs;
    }

    /**
     * Fetches a draft order's current state directly from Shopify — used by
     * the historical backfill (team-sponsorship:backfill-order-items) to
     * recover the product/quantity breakdown for orders created before
     * order_items existed. Confirmed live (2026-09-04) against a real
     * sponsorship draft order: root key `draft_order`, `line_items[]` each
     * with `variant_id`/`product_id`/`title`/`quantity`.
     */
    public function getDraftOrder(int $draftOrderId): array
    {
        $response = $this->get("/admin/api/2024-01/draft_orders/{$draftOrderId}.json");

        if ($response->failed()) {
            throw new RuntimeException("Shopify API error (HTTP {$response->status()}) while fetching draft order {$draftOrderId}.");
        }

        $data = $response->json();

        if (! isset($data['draft_order'])) {
            throw new RuntimeException("Unexpected Shopify response for draft order {$draftOrderId}.");
        }

        return $data['draft_order'];
    }

    /**
     * Matches a line item's variant_id back to this tool's own curated
     * product labels — null if it doesn't match any of the 4 known
     * sponsorship products (e.g. a product/variant since changed), in which
     * case the caller falls back to Shopify's own raw line-item title.
     */
    public function productLabelForVariantId(int $variantId): ?string
    {
        foreach (['bottles', 'sample_packs', 'team_towels', 'hockey_pucks'] as $key) {
            if ((int) config("team_sponsorship.{$key}.variant_id") === $variantId) {
                return config("team_sponsorship.{$key}.label");
            }
        }

        return null;
    }

    protected function truncateTag(string $tag, int $maxLength = 40): string
    {
        return mb_strlen($tag, 'UTF-8') > $maxLength ? mb_substr($tag, 0, $maxLength, 'UTF-8') : $tag;
    }

    /**
     * Found issue 32: a blank store made a malformed URL, which failed the
     * whole page. Refuse with a message the page can show instead.
     */
    protected function ensureConfigured(): void
    {
        if (blank($this->store) || blank($this->accessToken)) {
            throw new RuntimeException("BioSteel's Shopify store isn't configured in this environment yet (SHOPIFY_STORE and SHOPIFY_ACCESS_TOKEN).");
        }
    }

    protected function get(string $path): Response
    {
        $this->ensureConfigured();

        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
        ])->get("https://{$this->store}{$path}");
    }

    protected function post(string $path, array $body): Response
    {
        $this->ensureConfigured();

        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
        ])->post("https://{$this->store}{$path}", $body);
    }
}
