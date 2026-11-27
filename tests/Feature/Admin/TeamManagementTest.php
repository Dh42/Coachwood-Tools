<?php

namespace Tests\Feature\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_can_create_a_team_and_manage_membership(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($admin)->post(route('admin.teams.store'), ['name' => 'Amazon'])
            ->assertRedirect();

        $team = Team::where('name', 'Amazon')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.teams.members.store', $team), ['user_id' => $alice->id]);
        $this->actingAs($admin)->post(route('admin.teams.members.store', $team), ['user_id' => $bob->id]);

        $this->assertEqualsCanonicalizing(
            [$alice->id, $bob->id],
            $team->members()->pluck('users.id')->all()
        );

        $this->actingAs($admin)->delete(route('admin.teams.members.destroy', [$team, $alice]));

        $this->assertEquals([$bob->id], $team->members()->pluck('users.id')->all());
    }

    public function test_a_user_can_belong_to_multiple_teams(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $teamA = Team::create(['name' => 'Amazon']);
        $teamB = Team::create(['name' => 'Shopify']);

        $this->actingAs($admin)->post(route('admin.teams.members.store', $teamA), ['user_id' => $user->id]);
        $this->actingAs($admin)->post(route('admin.teams.members.store', $teamB), ['user_id' => $user->id]);

        $this->assertEqualsCanonicalizing(
            [$teamA->id, $teamB->id],
            $user->teams()->pluck('teams.id')->all()
        );
    }

    public function test_non_admin_cannot_manage_teams(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('admin.teams.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.teams.store'), ['name' => 'Nope'])->assertForbidden();
    }
}
