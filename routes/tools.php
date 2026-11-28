<?php

use App\Http\Controllers\Tools\AspTrackerController;
use App\Http\Controllers\Tools\CdqReportController;
use App\Http\Controllers\Tools\FeedbackController;
use App\Http\Controllers\Tools\StockoutController;
use App\Http\Controllers\Tools\TeamAppStatsController;
use App\Http\Controllers\Tools\TeamSponsorshipController;
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

// Team Sponsorship and its Stats page are granted separately: Stats sits
// under the tool's URL but has its own permission.
Route::middleware(['auth', 'permission:tool.team-sponsorship'])
    ->prefix('tools/team-sponsorship')
    ->name('tools.team-sponsorship.')
    ->group(function () {
        Route::get('/', [TeamSponsorshipController::class, 'index'])->name('index');
        Route::post('/search', [TeamSponsorshipController::class, 'search'])->name('search');
        Route::post('/orders', [TeamSponsorshipController::class, 'storeOrder'])->name('orders.store');
    });

Route::middleware(['auth', 'permission:tool.team-sponsorship-stats'])
    ->prefix('tools/team-sponsorship/stats')
    ->name('tools.team-sponsorship.')
    ->group(function () {
        Route::get('/', [TeamSponsorshipController::class, 'stats'])->name('stats');
        Route::get('/export', [TeamSponsorshipController::class, 'exportStats'])->name('stats.export');
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

Route::middleware(['auth', 'permission:tool.cdq-report'])
    ->prefix('tools/cdq-report')
    ->name('tools.cdq-report.')
    ->group(function () {
        Route::get('/', [CdqReportController::class, 'index'])->name('index');
        Route::get('/export.csv', [CdqReportController::class, 'exportCsv'])->name('export-csv');
        Route::get('/{asin}', [CdqReportController::class, 'show'])->name('show');
    });

// One ASP tracker tool per store (asp_tracker.stores.*.tool_slug): Canadian
// Protein at /tools/asp-tracker, BioSteel at /tools/asp-tracker-bs.
foreach (config('asp_tracker.stores', []) as $aspStore => $aspConfig) {
    Route::middleware(['auth', "permission:tool.{$aspConfig['tool_slug']}"])
        ->prefix("tools/{$aspConfig['tool_slug']}")
        ->name("tools.{$aspConfig['tool_slug']}.")
        ->group(function () use ($aspStore) {
            Route::get('/', [AspTrackerController::class, 'index'])->defaults('store', $aspStore)->name('index');
            Route::get('/drilldown', [AspTrackerController::class, 'drilldown'])->defaults('store', $aspStore)->name('drilldown');
            Route::get('/export.csv', [AspTrackerController::class, 'exportCsv'])->defaults('store', $aspStore)->name('export-csv');
        });
}
