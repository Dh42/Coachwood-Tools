<?php

namespace Tests\Feature\Skeleton;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SafetyDefaultsTest extends TestCase
{
    public function test_the_legacy_connection_is_an_in_memory_sqlite_database_in_tests(): void
    {
        $this->assertSame('sqlite', config('database.connections.legacy.driver'));
        $this->assertSame(':memory:', config('database.connections.legacy.database'));
        $this->assertSame('sqlite', DB::connection('legacy')->getDriverName());
    }

    public function test_unfaked_http_requests_are_blocked(): void
    {
        $this->expectException(RuntimeException::class);

        Http::get('https://example.com/');
    }

    public function test_the_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_home_page_names_the_app_and_nothing_else(): void
    {
        $this->followingRedirects()->get('/')
            ->assertOk()
            ->assertSee('Coachwood Tools')
            ->assertDontSee('Laravel');
    }
}
