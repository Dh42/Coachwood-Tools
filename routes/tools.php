<?php

use App\Http\Controllers\Tools\TeamAppStatsController;
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
