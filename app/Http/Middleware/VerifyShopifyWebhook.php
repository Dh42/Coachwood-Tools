<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every Shopify webhook is signed with the owning app's client secret
 * (config('stockout.webhook_secret') — NOT the Shopify access token, a
 * separate credential), base64(HMAC-SHA256(rawBody)) in the
 * X-Shopify-Hmac-Sha256 header. Refuses the request outright (401) if the
 * secret isn't configured, rather than skipping verification — a missing
 * secret must never silently become "accept anything."
 *
 * A route for a different app passes its own secret's config key as the
 * middleware parameter (e.g. the ASP tracker's
 * `verify.shopify-webhook:asp_tracker.stores.CP.webhook_secret`); without
 * one it's Stockout's.
 */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next, string $secretConfigKey = 'stockout.webhook_secret'): Response
    {
        $secret = (string) config($secretConfigKey);
        $header = (string) $request->header('X-Shopify-Hmac-Sha256');

        if (blank($secret) || blank($header)) {
            return response('Unauthorized.', 401);
        }

        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($computed, $header)) {
            return response('Unauthorized.', 401);
        }

        return $next($request);
    }
}
