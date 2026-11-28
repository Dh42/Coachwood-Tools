<?php

namespace App\Console\Concerns;

use Illuminate\Support\Facades\Process;

/**
 * Runs another Artisan command as its own PHP process, streaming its output.
 * `legacy:sync` and `cutover:move` run many imports and comparisons; in one
 * process their memory added up until a comparison passed the server's
 * 256 MB limit (2026-12-08). A process per step uses only what that step
 * needs, exactly as running it by hand would. Environment variables are
 * passed through.
 */
trait RunsStepsInOwnProcess
{
    /** @param  list<string>  $options  e.g. ['--dry-run'] */
    protected function runStep(string $command, array $options = []): int
    {
        $line = implode(' ', array_map('escapeshellarg', [PHP_BINARY, 'artisan', $command, ...$options]));

        return Process::path(base_path())->forever()
            ->run($line, fn (string $type, string $output) => $this->output->write($output))
            ->exitCode() ?? 1;
    }
}
