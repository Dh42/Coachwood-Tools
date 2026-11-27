<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PHP CLI binary for background-spawned scheduled task runs
    |--------------------------------------------------------------------------
    |
    | PHP_BINARY is only reliable from a real CLI (or cli-server) process —
    | from inside a PHP-FPM worker (exactly where Admin > Scheduler's
    | "Run Now" button executes), it commonly resolves to the FPM binary
    | itself, not a usable `php` CLI executable. Passing that to `exec()`
    | doesn't run the artisan command at all, it just fails silently (no
    | process, no error surfaced — see BackgroundProcessLauncher's own
    | docblock for the full incident writeup). Left null by default so
    | CLI/testing contexts (where PHP_BINARY is already correct) keep
    | working unchanged; set SCHEDULER_PHP_CLI_BINARY explicitly wherever
    | "Run Now" needs to work from the web (this app's own deploy script
    | already resolves this exact path for Plesk — reuse the same one).
    |
    */

    'php_cli_binary' => env('SCHEDULER_PHP_CLI_BINARY'),

];
