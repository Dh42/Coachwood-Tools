<?php

namespace Tests\Feature\Console;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Regression for CI run #20: `composer install` runs `artisan
 * package:discover` before there is a database (CI's Pint job has none),
 * and routes/console.php used to query scheduled_tasks on every Artisan
 * command, so the install failed. Only the scheduler commands may need
 * the database. Runs a real `php artisan` process, pointed at a database
 * file that doesn't exist.
 */
class ArtisanWithoutDatabaseTest extends TestCase
{
    public function test_artisan_commands_other_than_the_scheduler_run_without_a_database(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'package:discover'], base_path(), [
            'APP_ENV' => 'local',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => storage_path('framework/testing/no-such-database.sqlite'),
        ]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }
}
