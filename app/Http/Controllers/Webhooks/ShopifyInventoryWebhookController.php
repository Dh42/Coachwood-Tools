<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Stockout\InventorySnapshotService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives inventory_levels/update. Payload shape (inventory_item_id,
 * location_id, available, updated_at) is Shopify's long-stable classic
 * webhook format — see InventorySnapshotService::recordWebhookEvent()'s
 * docblock for why this one wasn't live-fired to confirm, unlike everything
 * else this session verified directly.
 */
class ShopifyInventoryWebhookController extends Controller
{
    public function __invoke(Request $request, InventorySnapshotService $service): Response
    {
        $payload = $request->json()->all();

        $inventoryItemId = $payload['inventory_item_id'] ?? null;
        $locationId = $payload['location_id'] ?? null;
        $available = $payload['available'] ?? null;

        if ($inventoryItemId === null || $locationId === null || $available === null) {
            return response('Malformed payload.', 422);
        }

        $capturedAt = isset($payload['updated_at']) ? Carbon::parse($payload['updated_at']) : now();

        $service->recordWebhookEvent(
            "gid://shopify/InventoryItem/{$inventoryItemId}",
            "gid://shopify/Location/{$locationId}",
            (int) $available,
            $capturedAt
        );

        return response('', 200);
    }
}
