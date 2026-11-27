<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\AspTracker\PendingOrderProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives the ASP tracker's order/refund webhooks. Only queues the order id
 * and returns 200 straight away — the payload itself is never trusted for
 * completeness; asp:process-pending re-fetches the order via GraphQL within
 * a minute. Queuing by order id makes every topic idempotent.
 */
class AspOrderWebhookController extends Controller
{
    public function __invoke(Request $request, string $store): Response
    {
        $topic = (string) $request->header('X-Shopify-Topic');
        $payload = $request->json()->all();

        // refunds/create carries the refund's own id in `id`; the order is `order_id`.
        $orderId = $topic === 'refunds/create' ? ($payload['order_id'] ?? null) : ($payload['id'] ?? null);

        if (! is_numeric($orderId)) {
            return response('Malformed payload.', 422);
        }

        PendingOrderProcessor::enqueue($store, [(int) $orderId], 'webhook:'.($topic ?: 'unknown'));

        return response('OK', 200);
    }
}
