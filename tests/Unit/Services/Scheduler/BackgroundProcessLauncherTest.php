<?php

namespace Tests\Unit\Services\Scheduler;

use App\Services\Scheduler\BackgroundProcessLauncher;
use Tests\TestCase;

class BackgroundProcessLauncherTest extends TestCase
{
    /**
     * Regression: PHP_BINARY is only reliable from a real CLI process — from
     * inside a genuine PHP-FPM worker (exactly where Admin > Scheduler's
     * "Run Now" executes) it commonly resolves to the FPM binary itself,
     * silently producing a shell command that never runs anything at all.
     * This is exactly the assumption that broke production and went
     * unnoticed because it was never actually asserted anywhere.
     */
    public function test_uses_the_configured_php_cli_binary_when_set(): void
    {
        config(['scheduler.php_cli_binary' => '/opt/plesk/php/8.3/bin/php']);

        $cmd = (new BackgroundProcessLauncher)->buildShellCommand('stock-forecast:run');

        $this->assertStringContainsString(escapeshellarg('/opt/plesk/php/8.3/bin/php'), $cmd);
        $this->assertStringNotContainsString(PHP_BINARY, $cmd);
    }

    public function test_falls_back_to_php_binary_constant_when_unconfigured(): void
    {
        config(['scheduler.php_cli_binary' => null]);

        $cmd = (new BackgroundProcessLauncher)->buildShellCommand('stock-forecast:run');

        $this->assertStringContainsString(escapeshellarg(PHP_BINARY), $cmd);
    }

    public function test_wraps_in_setsid_and_nohup_so_the_child_survives_the_parent_process_group(): void
    {
        $cmd = (new BackgroundProcessLauncher)->buildShellCommand('stock-forecast:run');

        $this->assertStringStartsWith('setsid nohup ', $cmd);
        $this->assertStringEndsWith('&', trim($cmd));
    }

    public function test_includes_the_artisan_path_command_and_arguments_correctly_escaped(): void
    {
        $cmd = (new BackgroundProcessLauncher)->buildShellCommand('schedule:execute-run', ['42']);

        $this->assertStringContainsString(escapeshellarg(base_path('artisan')), $cmd);
        $this->assertStringContainsString(escapeshellarg('schedule:execute-run'), $cmd);
        $this->assertStringContainsString(escapeshellarg('42'), $cmd);
    }
}
