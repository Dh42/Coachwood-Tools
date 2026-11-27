<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admins are superusers: every permission check (tool access
        // included) short-circuits to true for them, so they see/can use
        // every tool without needing the per-tool checkbox too.
        Gate::before(fn ($user) => $user->hasRole('admin') ? true : null);
    }
}
