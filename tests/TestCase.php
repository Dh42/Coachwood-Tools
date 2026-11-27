<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Whatever the environment says: the old database is never reachable
        // from a test, and nothing may write to an outside system. A shell
        // with LEGACY_TOOLS_DB_* exported once pointed the import tests at a
        // real database and they dropped its tables (2026-12-02).
        config([
            'database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'tools.external_writes' => false,
        ]);
        DB::purge('legacy');

        // No test may reach a real API. Any HTTP request that is not matched
        // by an Http::fake() throws instead of going out.
        Http::preventStrayRequests();

        // CI's test jobs don't build assets; the Vite job checks the build.
        $this->withoutVite();
    }
}
