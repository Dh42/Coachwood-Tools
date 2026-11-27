<?php

namespace App\Services\Scheduler;

/**
 * Spawns an artisan command as a truly detached background OS process —
 * genuinely fire-and-forget, unlike Laravel's Process::start(). Confirmed
 * live: Process::start()'s underlying Symfony Process registers a
 * destructor that sends a stop signal to the child when the PHP-side
 * Process object is garbage-collected (which happens at the end of the
 * request that spawned it), silently killing the child before it can do
 * anything — even though start() itself is documented as non-blocking.
 * Reproduced directly against this app's own bootstrap: a plain artisan
 * command spawned via Process::start() from inside a request never
 * completed, while the identical command spawned via exec()'s
 * `nohup ... > /dev/null 2>&1 &` shell-backgrounding idiom ran to
 * completion independently every time.
 *
 * `nohup` alone also turned out not to be enough for a genuinely
 * long-running command specifically: `nohup` only ignores SIGHUP, it does
 * NOT put the child in a new process group, so a spawned child stays in
 * the same process group as the PHP-FPM worker that spawned it and dies
 * if that group gets signaled (e.g. the worker being recycled once its
 * own HTTP request finishes — a fast child already avoids this by
 * finishing first). Confirmed with a standalone shell experiment: a plain
 * `nohup child &` did not survive `kill -TERM` on the parent's process
 * group; `setsid nohup child &` did. `setsid` starts the child in its own
 * session/process group, immune to the parent's. Real, but NOT what was
 * actually causing the production incident below — see the next fix.
 *
 * **The actual cause of "Run Now" hanging forever on Geo Sales/Marketplace
 * Forecast/NRP/Stock Forecast** (setsid alone didn't fix it — confirmed
 * live in production, then root-caused here): PHP_BINARY is only reliable
 * from a real CLI process. From inside a genuine PHP-FPM worker — exactly
 * where "Run Now" executes — it commonly resolves to the FPM binary
 * itself, not a usable `php` CLI executable. `exec()`ing that with
 * `artisan some:command` as arguments doesn't run anything; it fails
 * immediately and silently (output redirected to /dev/null), leaving
 * behind a `ScheduledTaskRun` row with `started_at` set and no process
 * ever having existed to close it out — confirmed live: `ps auxf` while a
 * run sat at "Running" for 12+ minutes showed no such process at all, not
 * even a crashed/zombie one. A fast command like `inspire` never exposed
 * this during local testing because local testing only ever ran under
 * `php artisan serve`'s cli-server SAPI, where PHP_BINARY is already
 * correct — real PHP-FPM was never exercised until this incident. Fixed
 * by resolving the CLI binary via `config('scheduler.php_cli_binary')`
 * instead of the PHP_BINARY constant — see config/scheduler.php.
 *
 * Wrapped in its own class (rather than calling exec() inline) purely so
 * it's swappable in tests — exec() itself has no fake()/mock() equivalent.
 */
class BackgroundProcessLauncher
{
    public function launchArtisanCommand(string $command, array $args = []): void
    {
        exec($this->buildShellCommand($command, $args));
    }

    /**
     * Split out from launchArtisanCommand() purely so the resolved PHP
     * binary and full shell command are actually assertable in a test —
     * exec() itself has no fake()/mock() equivalent, which is exactly how
     * the PHP_BINARY assumption this replaced went unverified until it
     * broke production.
     */
    public function buildShellCommand(string $command, array $args = []): string
    {
        $phpBinary = config('scheduler.php_cli_binary') ?: PHP_BINARY;
        $parts = [escapeshellarg($phpBinary), escapeshellarg(base_path('artisan')), escapeshellarg($command)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }

        return 'setsid nohup '.implode(' ', $parts).' > /dev/null 2>&1 < /dev/null &';
    }
}
