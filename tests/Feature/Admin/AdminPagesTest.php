<?php

namespace Tests\Feature\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Admin > Users, Teams and Activity beyond the ported tests. */
class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'Admin Example'])->assignRole('admin');
    }

    public function test_the_admin_group_in_the_sidebar_links_every_admin_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/dashboard');

        foreach (['admin.users.index', 'admin.teams.index', 'admin.tools.index', 'admin.activity.index'] as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }
    }

    public function test_admin_home_goes_to_users(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertRedirect('/admin/users');
    }

    public function test_every_admin_page_is_for_admins_only(): void
    {
        $team = Team::create(['name' => 'Amazon']);
        $user = User::factory()->create()->assignRole('user');

        foreach ([route('admin.users.index'), route('admin.users.edit', $user), route('admin.teams.index'), route('admin.teams.create'), route('admin.teams.edit', $team), route('admin.activity.index')] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_the_users_list_shows_status_and_offers_no_deactivate_on_your_own_row(): void
    {
        $disabled = User::factory()->create(['disabled_at' => now()]);
        $pending = User::factory()->create(['password' => null, 'google_id' => null]);

        $this->actingAs($this->admin)->get(route('admin.users.index'))
            ->assertSee('Deactivated')
            ->assertSee('Not signed in yet')
            ->assertSee('Admin Example')
            ->assertSee('id="delete-user-'.$disabled->id.'"', false)
            ->assertSee('id="deactivate-user-'.$pending->id.'"', false)
            ->assertDontSee('id="deactivate-user-'.$this->admin->id.'"', false);
    }

    public function test_activating_a_user_restores_sign_in_and_is_logged(): void
    {
        $target = User::factory()->create(['disabled_at' => now()]);

        $this->actingAs($this->admin)->patch(route('admin.users.activate', $target))->assertRedirect(route('admin.users.index'));

        $this->assertFalse($target->fresh()->isDisabled());
        $this->assertSame('Account activated', Activity::query()->latest('id')->value('description'));
    }

    public function test_an_admin_can_make_another_user_an_admin_and_the_change_is_logged(): void
    {
        $target = User::factory()->create()->assignRole('user');

        $this->actingAs($this->admin)->put(route('admin.users.update', $target), ['role' => 'admin'])
            ->assertRedirect(route('admin.users.edit', $target));

        $this->assertTrue($target->fresh()->hasRole('admin'));
        $entry = Activity::query()->latest('id')->firstOrFail();
        $this->assertSame('User updated', $entry->description);
        $this->assertSame(['role changed from user to admin'], $entry->getExtraProperty('changes'));
    }

    public function test_the_edit_form_ticks_the_users_teams(): void
    {
        $team = Team::create(['name' => 'Amazon']);
        $target = User::factory()->create()->assignRole('user');
        $target->teams()->attach($team);

        $this->actingAs($this->admin)->get(route('admin.users.edit', $target))
            ->assertSeeInOrder(['name="teams[]"', 'value="'.$team->id.'"', 'checked', 'Amazon'], false);
    }

    public function test_a_team_can_be_renamed_and_deleted_without_touching_its_members(): void
    {
        $team = Team::create(['name' => 'Amazon']);
        $member = User::factory()->create();
        $team->members()->attach($member);

        $this->actingAs($this->admin)->put(route('admin.teams.update', $team), ['name' => 'Amazon CA'])->assertRedirect(route('admin.teams.edit', $team));
        $this->assertSame('Amazon CA', $team->fresh()->name);

        $this->actingAs($this->admin)->delete(route('admin.teams.destroy', $team))->assertRedirect(route('admin.teams.index'));
        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
        $this->assertDatabaseMissing('team_user', ['user_id' => $member->id]);
        $this->assertNotNull($member->fresh());
    }

    public function test_team_names_must_be_unique(): void
    {
        Team::create(['name' => 'Amazon']);

        $this->actingAs($this->admin)->post(route('admin.teams.store'), ['name' => 'Amazon'])->assertSessionHasErrors('name');
    }

    public function test_the_activity_viewer_pages_25_at_a_time_newest_first(): void
    {
        foreach (range(1, 30) as $n) {
            activity('auth')->log("Entry {$n}");
        }

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertSee('Entry 30')
            ->assertDontSee('Entry 5<', false)
            ->assertSee('Page 1 of 2')
            ->assertSee('rel="next"', false);

        $this->actingAs($this->admin)->get(route('admin.activity.index', ['page' => 2]))
            ->assertSee('Entry 5<', false);
    }

    public function test_the_activity_viewer_names_the_subject(): void
    {
        $team = Team::create(['name' => 'Amazon']);
        activity('admin')->causedBy($this->admin)->performedOn($team)->log('Team created');

        $this->actingAs($this->admin)->get(route('admin.activity.index'))
            ->assertSee('Team: Amazon')
            ->assertSee('Admin Example');
    }
}
