<?php

use App\Http\Controllers\Webhooks\AspOrderWebhookController;
use App\Http\Controllers\Webhooks\ShopifyInventoryWebhookController;
use Illuminate\Support\Facades\Route;

// No Shopify subscription points here until this tool cuts over (decision
// "Webhooks"): until then the 15-minute sweep is the only source of
// snapshots. The handler is a primary-key read and one insert.
Route::post('/webhooks/shopify/inventory-levels-update', ShopifyInventoryWebhookController::class)
    ->middleware('verify.shopify-webhook')
    ->name('webhooks.shopify.inventory-levels-update');

// ASP Tracker: one receiver per store, each verified against that store's
// own app secret. Every topic (orders/create|updated|cancelled,
// refunds/create) lands here; X-Shopify-Topic tells them apart. Like the
// inventory webhook, nothing points here until cutover.
foreach (array_keys(config('asp_tracker.stores', [])) as $aspStore) {
    Route::post('/webhooks/shopify/asp/'.strtolower($aspStore), AspOrderWebhookController::class)
        ->middleware("verify.shopify-webhook:asp_tracker.stores.{$aspStore}.webhook_secret")
        ->defaults('store', $aspStore)
        ->name('webhooks.shopify.asp.'.strtolower($aspStore));
}
