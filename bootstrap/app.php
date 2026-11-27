<?php

use App\Http\Middleware\SignOutDisabledUsers;
use App\Models\ScheduledTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Set by resources/js/preferences.js and read when rendering <html>.
        $middleware->encryptCookies(except: ['theme', 'font_size']);

        $middleware->web(append: [SignOutDisabledUsers::class]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    // The scheduler is database-driven (Admin > Scheduler), as in the old
    // app: the server's one cron entry runs `schedule:run` every minute, and
    // every enabled scheduled_tasks row is registered here. withSchedule
    // runs only when a scheduler command resolves the schedule, so other
    // Artisan commands, `composer install`'s package:discover included,
    // never need the database. The old app did this in routes/console.php
    // on every Artisan command (CI run #20 failed on it).
    ->withSchedule(function (Schedule $schedule): void {
        // Not yet migrated on a fresh install.
        if (Schema::hasTable('scheduled_tasks')) {
            ScheduledTask::registerAllOnto($schedule);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
