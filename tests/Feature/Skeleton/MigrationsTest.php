<?php

namespace Tests\Feature\Skeleton;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_run_on_the_configured_database(): void
    {
        foreach (['users', 'sessions', 'cache', 'jobs', 'failed_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }
}
