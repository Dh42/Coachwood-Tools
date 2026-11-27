<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Regression, 2026-12-02: a shell with LEGACY_TOOLS_DB_* exported (as for
 * running an import against the restored local copy) pointed tests at that
 * database, and the import tests dropped its tables. phpunit.xml's
 * force="true" sets getenv() and $_ENV but not $_SERVER, which Laravel
 * reads first. The same gap applied to TOOLS_EXTERNAL_WRITES.
 */
class TestSafeguardsTest extends TestCase
{
    public function test_inside_a_test_the_legacy_connection_is_in_memory_sqlite_and_external_writes_are_off(): void
    {
        $this->assertSame('sqlite', config('database.connections.legacy.driver'));
        $this->assertSame(':memory:', config('database.connections.legacy.database'));
        $this->assertFalse(config('tools.external_writes'));
    }

    public function test_exported_shell_settings_cannot_reach_a_test(): void
    {
        if (getenv('SAFEGUARD_PROBE') === '1') {
            $this->markTestSkipped('Already inside the probe.');
        }

        // A dead port and a made-up database: nothing can be reached even if the safeguard fails.
        $process = new Process([PHP_BINARY, base_path('vendor/bin/phpunit'), '--filter', 'test_inside_a_test_the_legacy_connection_is_in_memory_sqlite_and_external_writes_are_off', __FILE__], base_path(), [
            'SAFEGUARD_PROBE' => '1',
            'LEGACY_TOOLS_DB_DRIVER' => 'mariadb',
            'LEGACY_TOOLS_DB_HOST' => '127.0.0.1',
            'LEGACY_TOOLS_DB_PORT' => '1',
            'LEGACY_TOOLS_DB_DATABASE' => 'made_up_database',
            'TOOLS_EXTERNAL_WRITES' => 'true',
        ]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
