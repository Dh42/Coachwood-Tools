<?php

namespace Tests\Feature\Tools;

use App\Models\ScheduledTask;
use App\Models\TeamAppSnapshot;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The rebuilt page and the registration, beyond the ported TeamAppStatsTest. */
class TeamAppStatsPageTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo('tool.team-app-stats');

        return $user;
    }

    public function test_the_tool_and_its_three_tasks_are_registered(): void
    {
        $tool = Tool::where('slug', 'team-app-stats')->sole();
        $this->assertSame('BioSteel Teams', $tool->group);
        $this->assertSame('/tools/team-app-stats', $tool->url);

        $tasks = ScheduledTask::query()->where('command', 'like', 'team-app-stats:%')->pluck('timezone', 'command')->all();
        $this->assertEqualsCanonicalizing(['team-app-stats:snapshot', 'team-app-stats:collect-ads', 'team-app-stats:snapshot-social'], array_keys($tasks));
        $this->assertSame('America/Toronto', $tasks['team-app-stats:snapshot-social']);
    }

    public function test_someone_with_access_sees_it_in_the_launcher_and_sidebar(): void
    {
        $this->actingAs($this->viewer())->get('/dashboard')
            ->assertSee('BioSteel Teams App Stats')
            ->assertSee('href="'.url('/tools/team-app-stats').'"', false);
    }

    public function test_the_page_has_its_tutorial_and_the_endpoints_it_calls(): void
    {
        TeamAppSnapshot::create(['captured_at' => now()->subHour()->startOfHour(), 'users' => 10, 'teams' => 2]);

        $this->actingAs($this->viewer())->get(route('tools.team-app-stats.index'))
            ->assertOk()
            ->assertSee('data-tour="tas-tour-btn-steps"', false)
            ->assertSee('id="tas-tour-btn-steps"', false)
            ->assertSee('#tas-kpi-cards', false)
            ->assertSee('id="tas-config"', false)
            ->assertSee(str_replace('/', '\/', route('tools.team-app-stats.ga4-data')), false);
    }

    public function test_growth_shows_its_sign_so_colour_is_not_the_only_cue(): void
    {
        TeamAppSnapshot::create(['captured_at' => now()->subDays(2)->startOfHour(), 'users' => 100, 'teams' => 20]);
        TeamAppSnapshot::create(['captured_at' => now()->subHour()->startOfHour(), 'users' => 90, 'teams' => 25]);

        $this->actingAs($this->viewer())->get(route('tools.team-app-stats.index'))
            ->assertSee('class="mult-signed delta-down " data-raw="-10">-10<', false)
            ->assertSee('class="mult-signed delta-up " data-raw="5">+5<', false);
    }
}
