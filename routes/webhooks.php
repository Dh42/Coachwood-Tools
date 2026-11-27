<?php

use App\Http\Controllers\Webhooks\ShopifyInventoryWebhookController;
use Illuminate\Support\Facades\Route;

// No Shopify subscription points here until this tool cuts over (decision
// "Webhooks"): until then the 15-minute sweep is the only source of
// snapshots. The handler is a primary-key read and one insert.
Route::post('/webhooks/shopify/inventory-levels-update', ShopifyInventoryWebhookController::class)
    ->middleware('verify.shopify-webhook')
    ->name('webhooks.shopify.inventory-levels-update');
