<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real API. Any HTTP request that is not matched
        // by an Http::fake() throws instead of going out.
        Http::preventStrayRequests();

        // CI's test jobs don't build assets; the Vite job checks the build.
        $this->withoutVite();
    }
}
