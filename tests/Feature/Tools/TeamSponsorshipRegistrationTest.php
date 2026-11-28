<?php

namespace Tests\Feature\Tools;

use App\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Replaces the old TeamSponsorshipToolRegistrationTest. Its three tests
 * covered one-off migrations that repaired a tool row created by hand under
 * the wrong slug; here tools are registered by slug (ToolRegistry) and
 * imported by slug, so that state can't arise. What they protected is kept:
 * each page is reachable under its own slug and permission, once.
 */
class TeamSponsorshipRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_tools_and_the_nightly_refresh_are_registered_once(): void
    {
        foreach (['team-sponsorship' => '/tools/team-sponsorship', 'team-sponsorship-stats' => '/tools/team-sponsorship/stats'] as $slug => $url) {
            $tool = Tool::where('url', $url)->sole();
            $this->assertSame($slug, $tool->slug);
            $this->assertSame('BioSteel Teams', $tool->group);
            $this->assertTrue(Permission::where('name', "tool.{$slug}")->exists());
        }

        $task = DB::table('scheduled_tasks')->where('command', 'team-sponsorship:refresh-activity')->sole();
        $this->assertSame('0 1 * * *', $task->cron_expression);
        $this->assertNull($task->timezone);

        // Running the registration again adds nothing.
        (require database_path('migrations/2026_12_05_000002_register_team_sponsorship.php'))->up();
        $this->assertSame(1, Tool::where('url', '/tools/team-sponsorship')->count());
        $this->assertSame(1, Permission::where('name', 'tool.team-sponsorship')->count());
        $this->assertSame(1, DB::table('scheduled_tasks')->where('command', 'team-sponsorship:refresh-activity')->count());
    }

    /** The ported tests set these themselves, so only this shows the app reads them from .env. */
    public function test_the_store_and_api_settings_come_from_the_environment(): void
    {
        $services = require config_path('services.php');
        $this->assertArrayHasKey('store', $services['shopify']);
        $this->assertArrayHasKey('access_token', $services['shopify']);

        $klaviyo = require config_path('klaviyo.php');
        $this->assertArrayHasKey('private_api_key', $klaviyo['accounts']['BS']);
        $this->assertSame('Claimed Sponsorship Package', $klaviyo['events']['claimed_sponsorship_package']);
    }
}
