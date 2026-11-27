<?php

use App\Http\Controllers\Admin\ToolController;
use Illuminate\Support\Facades\Route;

// Admin pages. Ported route by route as each slice lands; names and URLs
// are the old app's. Users, Teams and Activity come in 3e, Google Sign-In
// in 3f, Mail in 3g and the Scheduler in 3h.
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Must be registered before the resource route below — otherwise
    // Route::resource's PATCH tools/{tool} would greedily match
    // "reorder" as a {tool} route-model-binding value first.
    Route::patch('tools/reorder', [ToolController::class, 'reorder'])->name('tools.reorder');
    Route::resource('tools', ToolController::class)->except(['show']);
});
