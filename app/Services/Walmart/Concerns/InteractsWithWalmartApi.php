<?php

namespace App\Services\Walmart\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared plumbing for every Walmart Marketplace API client (WalmartUsApiClient
 * now, WalmartCaApiClient in Phase 3) — OAuth2 client_credentials token
 * exchange/caching, the WM_QOS.CORRELATION_ID / WM_SVC.NAME headers Walmart
 * requires on every call, and rate-limit-aware backoff on 429.
 *
 * Live-verified against a real US Marketplace account (2026-09-03): the
 * gated docs turned out to hide two genuine surprises — auth uses a
 * WM_SEC.ACCESS_TOKEN header, NOT "Authorization: Bearer" (that gets a 401
 * whose own error message misleadingly points at Basic-auth docs); and 429s
 * don't carry Retry-After at all — instead an X-Next-Replenishment-Time
 * response header gives the exact epoch-ms timestamp the token bucket
 * refills at (confirmed: waiting until that timestamp reliably clears the
 * 429). Retry-After is kept as a secondary check in case some other
 * endpoint does send it; exponential backoff + jitter is the last-resort
 * fallback when neither header is present.
 */
trait InteractsWithWalmartApi
{
    /**
     * The `walmart.<key>` config block this client reads from — 'us' or 'ca'.
     */
    abstract protected function configKey(): string;

    protected function walmartConfig(): array
    {
        return config('walmart.'.$this->configKey());
    }

    /**
     * Access token exchange, cached 10 minutes. Confirmed live that the
     * previously-assumed 870s (14.5 min) TTL was NOT safe: a token fetched
     * at T+0 was rejected with a real 401 ("Invalid token") by T+13min
     * during a long report-polling run, meaning Walmart's real
     * client_credentials TTL is shorter than the commonly-assumed 900s (15
     * min). 10 minutes is a conservative margin under that observed
     * failure point, not a confirmed exact TTL.
     */
    protected function accessToken(): string
    {
        $cfg = $this->walmartConfig();

        return Cache::remember(
            'walmart_'.$this->configKey().'_access_token:'.md5((string) $cfg['client_id']),
            now()->addSeconds(600),
            function () use ($cfg) {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'WM_SVC.NAME' => 'Walmart Marketplace',
                    'WM_QOS.CORRELATION_ID' => (string) Str::uuid(),
                ])
                    ->withBasicAuth((string) $cfg['client_id'], (string) $cfg['client_secret'])
                    ->asForm()
                    ->timeout(15)
                    ->post(rtrim((string) $cfg['base_url'], '/').'/token', [
                        'grant_type' => 'client_credentials',
                    ]);

                $token = $response->json('access_token');
                if (! $response->successful() || ! $token) {
                    throw new RuntimeException('Walmart '.strtoupper($this->configKey()).' token exchange failed: '.$response->body());
                }

                return $token;
            }
        );
    }

    /**
     * @param  ?array  $jsonBody  Pass an empty array (not null) to send an
     *                            explicit `{}` JSON body — some Walmart
     *                            endpoints (confirmed: report creation)
     *                            415-reject a bodyless POST even when the
     *                            body is logically empty, the same
     *                            "Http::post() silently drops an
     *                            intentionally-empty body" class of gotcha
     *                            already documented on AdManagerClient. null
     *                            means "no body at all" (GETs).
     * @return array{status: int, body: ?array, correlation_id: string}
     */
    protected function request(string $method, string $path, array $query = [], ?array $jsonBody = null, array $extraHeaders = []): array
    {
        $cfg = $this->walmartConfig();
        $url = rtrim((string) $cfg['base_url'], '/').$path;
        $maxRetries = (int) ($cfg['max_retries'] ?? 5);
        $baseBackoffMs = (int) ($cfg['base_backoff_ms'] ?? 500);
        $maxBackoffMs = (int) ($cfg['max_backoff_ms'] ?? 30000);

        $attempt = 0;

        while (true) {
            $correlationId = (string) Str::uuid();

            $http = Http::withHeaders(array_merge([
                'WM_SEC.ACCESS_TOKEN' => $this->accessToken(),
                'Accept' => 'application/json',
                'WM_SVC.NAME' => 'Walmart Marketplace',
                'WM_QOS.CORRELATION_ID' => $correlationId,
            ], $extraHeaders))->timeout(30);

            if ($jsonBody !== null) {
                // json_encode([]) is '[]' (a JSON array), not the '{}'
                // object Walmart's schema validation wants — cast explicitly.
                $http = $http->withBody(json_encode($jsonBody === [] ? (object) [] : $jsonBody), 'application/json');
            }

            $response = $http->send($method, $url, array_filter(['query' => $query]));

            Log::info('Walmart '.strtoupper($this->configKey()).' API call', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'correlation_id' => $correlationId,
            ]);

            if ($response->status() !== 429 || $attempt >= $maxRetries) {
                return [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'correlation_id' => $correlationId,
                ];
            }

            $nextReplenishmentMs = (int) $response->header('X-Next-Replenishment-Time');
            $retryAfterHeader = $response->header('Retry-After');

            if ($nextReplenishmentMs > 0) {
                $delayMs = max(0, $nextReplenishmentMs - (int) (microtime(true) * 1000));
            } elseif ($retryAfterHeader !== '' && $retryAfterHeader !== null) {
                $delayMs = ((int) $retryAfterHeader) * 1000;
            } else {
                $delayMs = min($maxBackoffMs, $baseBackoffMs * (2 ** $attempt)) + random_int(0, $baseBackoffMs);
            }

            $delayMs = min($delayMs, $maxBackoffMs);

            usleep($delayMs * 1000);
            $attempt++;
        }
    }
}
