<?php

namespace Tests\Feature\Admin;

use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ToolPermissionGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
    }

    public function test_dashboard_hides_tools_the_user_has_no_permission_for(): void
    {
        $tool = Tool::create(['name' => 'Invoicing', 'slug' => 'invoicing', 'active' => true, 'sort_order' => 0]);
        Permission::findOrCreate($tool->permissionName());

        $user = User::factory()->create();
        $user->assignRole('user');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertDontSee('Invoicing');
    }

    public function test_dashboard_shows_tools_once_granted(): void
    {
        $tool = Tool::create(['name' => 'Invoicing', 'slug' => 'invoicing', 'active' => true, 'sort_order' => 0]);
        Permission::findOrCreate($tool->permissionName());

        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($tool->permissionName());

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSee('Invoicing');
    }

    public function test_inactive_tools_are_never_shown_even_with_permission(): void
    {
        $tool = Tool::create(['name' => 'Retired Tool', 'slug' => 'retired-tool', 'active' => false, 'sort_order' => 0]);
        Permission::findOrCreate($tool->permissionName());

        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($tool->permissionName());

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertDontSee('Retired Tool');
    }

    public function test_admins_see_every_active_tool_without_needing_the_permission_checkbox(): void
    {
        $tool = Tool::create(['name' => 'Invoicing', 'slug' => 'invoicing', 'active' => true, 'sort_order' => 0]);
        Permission::findOrCreate($tool->permissionName());

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertSee('Invoicing');
    }

    public function test_dashboard_shows_the_tool_description_and_emoji_icon(): void
    {
        $tool = Tool::create([
            'name' => 'Invoicing',
            'description' => 'Send and track customer invoices.',
            'icon' => '🧾',
            'slug' => 'invoicing',
            'active' => true,
            'sort_order' => 0,
        ]);
        Permission::findOrCreate($tool->permissionName());

        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($tool->permissionName());

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSee('Send and track customer invoices.');
        $response->assertSee('🧾');
    }
}
