<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\ToolController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Admin pages. Ported route by route as each slice lands; names and URLs
// are the old app's. Google Sign-In comes in 3f, Mail and inviting users
// (users.create, users.store, users.resend-invitation) in 3g, and the
// Scheduler in 3h.
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/users')->name('dashboard');

    // Must be registered before the resource route below — otherwise
    // Route::resource's PATCH tools/{tool} would greedily match
    // "reorder" as a {tool} route-model-binding value first.
    Route::patch('tools/reorder', [ToolController::class, 'reorder'])->name('tools.reorder');
    Route::resource('tools', ToolController::class)->except(['show']);

    Route::resource('teams', TeamController::class)->except(['show']);
    Route::post('teams/{team}/members', [TeamController::class, 'storeMember'])->name('teams.members.store');
    Route::delete('teams/{team}/members/{user}', [TeamController::class, 'destroyMember'])->name('teams.members.destroy');

    Route::resource('users', UserController::class)->only(['index', 'edit', 'update', 'destroy']);
    Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::patch('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');

    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
});
