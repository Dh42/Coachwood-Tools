<?php

use App\Http\Controllers\Tools\AspTrackerController;
use App\Http\Controllers\Tools\CdqReportController;
use App\Http\Controllers\Tools\FeedbackController;
use App\Http\Controllers\Tools\ImageManagerController;
use App\Http\Controllers\Tools\Listings\SellerListingController;
use App\Http\Controllers\Tools\Listings\VendorListingController;
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

Route::middleware(['auth', 'permission:tool.vc-listings'])
    ->prefix('tools/vc-listings')
    ->name('tools.vc-listings.')
    ->group(function () {
        Route::get('/', [VendorListingController::class, 'index'])->name('index');
        Route::post('/submit', [VendorListingController::class, 'submit'])->name('submit');
        Route::post('/audit/run', [VendorListingController::class, 'auditRun'])->name('audit.run');
        Route::post('/audit/batch', [VendorListingController::class, 'auditBatch'])->name('audit.batch');
        Route::post('/audit/config', [VendorListingController::class, 'auditConfig'])->name('audit.config');
        Route::post('/export/batch', [VendorListingController::class, 'exportBatch'])->name('export.batch');
    });

Route::middleware(['auth', 'permission:tool.sc-listings'])
    ->prefix('tools/sc-listings')
    ->name('tools.sc-listings.')
    ->group(function () {
        Route::get('/', [SellerListingController::class, 'index'])->name('index');
        Route::post('/submit', [SellerListingController::class, 'submit'])->name('submit');
        Route::post('/sync', [SellerListingController::class, 'syncProducts'])->name('sync');
        Route::post('/sync/start', [SellerListingController::class, 'syncStart'])->name('sync.start');
        Route::post('/sync/poll', [SellerListingController::class, 'syncPoll'])->name('sync.poll');
        Route::post('/audit/run', [SellerListingController::class, 'auditRun'])->name('audit.run');
        Route::post('/audit/batch', [SellerListingController::class, 'auditBatch'])->name('audit.batch');
        Route::post('/audit/config', [SellerListingController::class, 'auditConfig'])->name('audit.config');
    });

Route::middleware(['auth', 'permission:tool.vc-image-manager'])
    ->prefix('tools/vc-image-manager')
    ->name('tools.vc-image-manager.')
    ->group(function () {
        Route::get('/', [ImageManagerController::class, 'index'])->name('index');
        Route::post('/upload', [ImageManagerController::class, 'upload'])->name('upload');
        Route::post('/remove', [ImageManagerController::class, 'remove'])->name('remove');
        Route::post('/audit/run', [ImageManagerController::class, 'auditRun'])->name('audit.run');
        Route::post('/audit/batch', [ImageManagerController::class, 'auditBatch'])->name('audit.batch');
    });
