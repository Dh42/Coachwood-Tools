<?php

namespace App\Services\CombinedShipping;

use App\Support\HttpRetry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin GraphQL Admin API client for one brand's Shopify store — confirmed
 * live (2026-09-15) against the real BioSteel store on API version
 * 2026-07: shop{}, orders(query:), suggestedRefund, refundCreate all
 * resolve, and the leaky-bucket cost/throttle extension shape
 * (extensions.cost.throttleStatus.{maximumAvailable,currentlyAvailable,
 * restoreRate}) matches what's implemented here.
 *
 * Retry/backoff is genuinely leaky-bucket, not a fixed sleep (per the
 * brief's own instruction, since the GraphQL response hands back the exact
 * throttle state): on a THROTTLED error, wait only as long as it takes the
 * bucket to refill enough points for a typical next query, read straight
 * from this response's own throttleStatus — never a guessed fixed delay.
 * The THROTTLED error code/shape itself is Shopify's documented behavior
 * for the GraphQL Admin API (a 200 response with a top-level `errors`
 * entry carrying `extensions.code === 'THROTTLED'`) — not something this
 * session deliberately triggered live (doing so would mean burning real
 * API quota against the production store for no other reason), so treat
 * that one specific shape as inherited-from-docs, not live-confirmed,
 * unlike everything else in this class.
 */
class ShopifyGraphQlClient
{
    private const MAX_ATTEMPTS = 5;

    // A safety margin under a typical query's actual cost — waits until at
    // least this many points are available before retrying, rather than
    // the full maximumAvailable.
    private const ASSUMED_QUERY_COST = 50;

    public function __construct(protected string $brand) {}

    /** Throws the same RuntimeException query() would eventually hit, but immediately — lets a caller fail fast before doing any other work (e.g. persisting a batch) rather than discovering an unconfigured brand mid-loop. */
    public function ensureConfigured(): void
    {
        $this->config();
    }

    /**
     * @return array{status: int, data: ?array, errors: ?array, cost: ?array}
     */
    public function query(string $query, array $variables = []): array
    {
        $config = $this->config();
        $version = config('combined_shipping.api_version');
        $url = "https://{$config['store']}/admin/api/{$version}/graphql.json";

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $config['access_token'],
                'Content-Type' => 'application/json',
            ])->timeout(30)
                ->retry(HttpRetry::CONNECT_BACKOFF_MS, when: fn ($e) => HttpRetry::isConnectFailure($e), throw: false)
                ->post($url, array_filter([
                    'query' => $query,
                    'variables' => $variables,
                ]));

            $body = $response->json() ?? [];
            $errors = $body['errors'] ?? null;
            $cost = $body['extensions']['cost'] ?? null;

            Log::info('CombinedShipping Shopify GraphQL call', [
                'brand' => $this->brand,
                'attempt' => $attempt,
                'status' => $response->status(),
                'query_cost' => $cost['actualQueryCost'] ?? null,
                'throttled' => $this->isThrottled($errors),
            ]);

            if (! $this->isThrottled($errors) || $attempt === self::MAX_ATTEMPTS) {
                return [
                    'status' => $response->status(),
                    'data' => $body['data'] ?? null,
                    'errors' => $errors,
                    'cost' => $cost,
                ];
            }

            usleep($this->throttleDelayMs($cost['throttleStatus'] ?? null) * 1000);
        }

        throw new RuntimeException('Unreachable — loop always returns or throws before falling through.');
    }

    protected function config(): array
    {
        $config = config("combined_shipping.brands.{$this->brand}");

        if ($config === null) {
            throw new RuntimeException("Unknown Combined Shipping brand: {$this->brand}");
        }

        if (blank($config['store'] ?? null) || blank($config['access_token'] ?? null)) {
            throw new RuntimeException(
                "{$config['name']} isn't configured in this environment yet — its Shopify store/access token are blank. Add them to .env before using this brand."
            );
        }

        return $config;
    }

    private function isThrottled(?array $errors): bool
    {
        foreach ($errors ?? [] as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    /** Leaky-bucket wait: only as long as it takes to refill enough points for another query, read from this response's own throttle state. */
    private function throttleDelayMs(?array $throttleStatus): int
    {
        if ($throttleStatus === null) {
            return 1000; // no throttle state to read — a conservative flat fallback
        }

        $available = (float) ($throttleStatus['currentlyAvailable'] ?? 0);
        $restoreRate = (float) ($throttleStatus['restoreRate'] ?? 50);
        $needed = max(0.0, self::ASSUMED_QUERY_COST - $available);

        if ($restoreRate <= 0) {
            return 1000;
        }

        return (int) ceil($needed / $restoreRate * 1000);
    }
}
