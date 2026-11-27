<?php

use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check() ? redirect()->route('dashboard') : view('welcome');
})->name('home');

// The launcher arrives with the tools registry (3d).
Route::view('/dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::put('/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
});

require __DIR__.'/auth.php';

// The shared components with made-up values. Never on the server.
if (app()->environment('local', 'testing')) {
    Route::view('/dev/components', 'dev.components')->name('dev.components');
}
