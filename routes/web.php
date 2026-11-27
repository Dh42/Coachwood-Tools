<?php

use Illuminate\Support\Facades\Route;

// Placeholder until sign-in (3b) and the tools launcher (3d) are ported.
Route::view('/', 'home')->name('home');

// The shared components with made-up values. Never on the server.
if (app()->environment('local', 'testing')) {
    Route::view('/dev/components', 'dev.components')->name('dev.components');
}
