<?php

namespace App\Services\Stockout;

use App\Support\HttpRetry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin GraphQL Admin API client for the BioSteel store — same leaky-bucket
 * retry design as Combined Shipping's ShopifyGraphQlClient (confirmed live,
 * 2026-09-15, against the same store's throttleStatus shape); not shared
 * code with that class since it's brand-parameterized for a two-store tool
 * and this one is single-store by design (v1 is BioSteel only, per brief).
 *
 * Unlike Combined Shipping's client, this one can't rely on a static
 * long-lived access token: Stockout's dedicated custom app (see
 * config/stockout.php) was created via `shopify dev`/Partner Dashboard,
 * which has no "reveal token" screen — only the client credentials grant
 * (confirmed live 2026-09-27), whose token expires in ~24h. resolveAccessToken()
 * fetches and caches one when config('stockout.shopify.access_token') is
 * blank (the real-environment case); a configured value is used as-is
 * (tests, or a future long-lived token).
 */
class ShopifyGraphQlClient
{
    private const MAX_ATTEMPTS = 5;

    private const ASSUMED_QUERY_COST = 150;

    private const TOKEN_CACHE_KEY = 'stockout:shopify:access_token';

    // Comfortably under Shopify's observed ~24h (86399s) expiry — refreshed
    // well before expiry rather than cutting it close, so a 15-minute cron
    // sweep never races an about-to-expire cached token.
    private const CACHE_TTL_SECONDS = 20 * 3600;

    /**
     * @return array{status: int, data: ?array, errors: ?array, cost: ?array}
     */
    public function query(string $query, array $variables = [], bool $isRetryAfterReauth = false): array
    {
        $store = (string) config('stockout.shopify.store');

        if (blank($store)) {
            throw new RuntimeException('Stockout Shopify store is blank — set SHOPIFY_STORE.');
        }

        $token = $this->resolveAccessToken();
        $version = config('stockout.api_version');
        $url = "https://{$store}/admin/api/{$version}/graphql.json";

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->timeout(30)
                ->retry(HttpRetry::CONNECT_BACKOFF_MS, when: fn ($e) => HttpRetry::isConnectFailure($e), throw: false)
                ->post($url, array_filter([
                    'query' => $query,
                    'variables' => $variables,
                ]));

            // A cached token Shopify has since rejected (e.g. revoked, or
            // this cache outliving the real 24h expiry) — clear it and
            // retry the whole query exactly once with a freshly-fetched
            // token, rather than surfacing a confusing auth error to the
            // caller for something this class can self-heal.
            if ($response->status() === 401 && ! $isRetryAfterReauth) {
                Cache::forget(self::TOKEN_CACHE_KEY);

                return $this->query($query, $variables, true);
            }

            $body = $response->json() ?? [];
            $errors = $body['errors'] ?? null;
            $cost = $body['extensions']['cost'] ?? null;

            Log::info('Stockout Shopify GraphQL call', [
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

            usleep($this->throttleDelayMs($errors, $cost) * 1000);
        }

        throw new RuntimeException('Unreachable — loop always returns or throws before falling through.');
    }

    private function resolveAccessToken(): string
    {
        $configured = (string) config('stockout.shopify.access_token');

        if (filled($configured)) {
            return $configured;
        }

        // A fixed, conservative TTL rather than trusting Shopify's own
        // expires_in exactly — Cache::remember() needs the TTL before the
        // token call returns anyway. Confirmed live 2026-09-27 at ~24h
        // (86399s); this stays comfortably under that with room to spare.
        return Cache::remember(self::TOKEN_CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->fetchNewAccessToken());
    }

    private function fetchNewAccessToken(): string
    {
        $store = (string) config('stockout.shopify.store');
        $clientId = (string) config('stockout.shopify.client_id');
        $clientSecret = (string) config('stockout.shopify.client_secret');

        if (blank($clientId) || blank($clientSecret)) {
            throw new RuntimeException('Stockout Shopify credentials are blank — set STOCKOUT_SHOPIFY_CLIENT_ID/STOCKOUT_SHOPIFY_CLIENT_SECRET.');
        }

        $response = Http::timeout(30)
            ->retry(HttpRetry::CONNECT_BACKOFF_MS, when: fn ($e) => HttpRetry::isConnectFailure($e), throw: false)
            ->post("https://{$store}/admin/oauth/access_token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]);

        $token = $response->json('access_token');

        if ($response->failed() || blank($token)) {
            throw new RuntimeException('Failed to obtain a Stockout Shopify access token via client_credentials grant (HTTP '.$response->status().').');
        }

        Log::info('Stockout Shopify access token refreshed', ['expires_in' => $response->json('expires_in')]);

        return $token;
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

    /**
     * A THROTTLED error's own `extensions.cost`, when present — confirmed
     * live 2026-09-27 that `shopifyqlQuery` is metered by a completely
     * separate, fixed-window analytics bucket (1000 points/window,
     * `windowResetAt` absolute timestamp) reported ONLY inside the error
     * itself, not the response's top-level `extensions.cost` (which stayed
     * healthy — ~20000/20000 — the entire time this bucket was empty). A
     * real bug existed here before this was found: retries were computed
     * from the healthy top-level bucket, producing a near-zero delay that
     * burned all MAX_ATTEMPTS instantly inside the same rate-limit window.
     */
    private function throttledErrorCost(?array $errors): ?array
    {
        foreach ($errors ?? [] as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED' && isset($error['extensions']['cost'])) {
                return $error['extensions']['cost'];
            }
        }

        return null;
    }

    private function throttleDelayMs(?array $errors, ?array $topLevelCost): int
    {
        $errorCost = $this->throttledErrorCost($errors);

        if ($errorCost !== null && filled($errorCost['windowResetAt'] ?? null)) {
            $resetAt = Date::parse($errorCost['windowResetAt']);
            $secondsUntilReset = max(0, $resetAt->getTimestamp() - time());

            return ($secondsUntilReset * 1000) + 500; // small buffer past the window boundary
        }

        $throttleStatus = ($errorCost ?? $topLevelCost)['throttleStatus'] ?? null;

        if ($throttleStatus === null) {
            return 1000;
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
