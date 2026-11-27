<?php

use App\Http\Controllers\Tools\FeedbackController;
use App\Http\Controllers\Tools\StockoutController;
use App\Http\Controllers\Tools\TeamAppStatsController;
use App\Http\Controllers\Tools\WalmartUsAuditController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Tool pages, each behind its own `tool.<slug>` permission (admins pass
// every check). URLs and route names are the old app's.

Route::middleware(['auth', 'permission:tool.team-app-stats'])
    ->prefix('tools/team-app-stats')
    ->name('tools.team-app-stats.')
    ->group(function () {
        Route::get('/', [TeamAppStatsController::class, 'index'])->name('index');
        Route::get('/ga4-data', [TeamAppStatsController::class, 'ga4Data'])->name('ga4-data');
        Route::get('/ga4-realtime', [TeamAppStatsController::class, 'ga4Realtime'])->name('ga4-realtime');
        Route::get('/ga4-sponsor-data', [TeamAppStatsController::class, 'ga4SponsorData'])->name('ga4-sponsor-data');
        Route::get('/social/top-posts', [TeamAppStatsController::class, 'socialTopPosts'])->name('social.top-posts');
        Route::get('/social/top-followed', [TeamAppStatsController::class, 'socialTopFollowed'])->name('social.top-followed');
        Route::get('/social/history', [TeamAppStatsController::class, 'socialHistory'])->name('social.history');
    });

Route::middleware(['auth', 'permission:tool.walmart-us-listing-auditor'])
    ->prefix('tools/walmart-us-listing-auditor')
    ->name('tools.walmart-us-listing-auditor.')
    ->group(function () {
        Route::get('/', [WalmartUsAuditController::class, 'index'])->name('index');
        Route::get('/{sku}', [WalmartUsAuditController::class, 'show'])->name('show');
    });

Route::middleware(['auth', 'permission:tool.us-product-reviews'])
    ->get('tools/us-product-reviews', [FeedbackController::class, 'index'])
    ->name('tools.us-product-reviews.index');

// Renamed from Stockout (decision D7). The old URL redirects, keeping any
// date range in the query string.
Route::middleware(['auth', 'permission:tool.biosteel-shopify-lost-sales'])
    ->get('tools/biosteel-shopify-lost-sales', [StockoutController::class, 'index'])
    ->name('tools.biosteel-shopify-lost-sales.index');

Route::get('tools/stockout', fn (Request $request) => redirect()->route('tools.biosteel-shopify-lost-sales.index', $request->query(), 301))
    ->name('tools.stockout.redirect');
