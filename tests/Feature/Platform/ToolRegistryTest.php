<?php

namespace Tests\Feature\Platform;

use App\Models\Tool;
use App\Models\User;
use App\Tools\ToolRegistry;
use App\View\Icons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** The registry, launcher and sidebar beyond the ported tool tests. */
class ToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function tool(array $attributes): Tool
    {
        $tool = Tool::create($attributes + ['active' => true, 'sort_order' => 0]);
        Permission::findOrCreate($tool->permissionName(), 'web');

        return $tool;
    }

    public function test_a_registration_migration_creates_the_tool_and_its_permission_once(): void
    {
        $definition = ['slug' => 'po-checker', 'name' => 'PO Checker', 'description' => 'Check a PO.', 'group' => 'Amazon · Purchase orders', 'icon' => 'box', 'url' => '/tools/po-checker'];

        ToolRegistry::register($definition);
        ToolRegistry::register($definition);

        $tool = Tool::where('slug', 'po-checker')->sole();
        $this->assertSame('Amazon · Purchase orders', $tool->group);
        $this->assertTrue($tool->active);
        $this->assertSame(1, Permission::where('name', 'tool.po-checker')->count());
    }

    public function test_a_new_registration_goes_to_the_end_of_the_order(): void
    {
        $this->tool(['name' => 'First', 'slug' => 'first', 'sort_order' => 7]);

        ToolRegistry::register(['slug' => 'second', 'name' => 'Second', 'url' => '/tools/second']);

        $this->assertSame(8, Tool::where('slug', 'second')->value('sort_order'));
    }

    public function test_registering_again_never_overwrites_what_an_admin_changed(): void
    {
        ToolRegistry::register(['slug' => 'po-checker', 'name' => 'PO Checker', 'url' => '/tools/po-checker']);
        Tool::where('slug', 'po-checker')->update(['name' => 'Renamed by an admin', 'active' => false]);

        ToolRegistry::register(['slug' => 'po-checker', 'name' => 'PO Checker', 'url' => '/tools/po-checker']);

        $tool = Tool::where('slug', 'po-checker')->sole();
        $this->assertSame('Renamed by an admin', $tool->name);
        $this->assertFalse($tool->active);
    }

    public function test_unregistering_removes_the_tool_its_permission_and_its_grants(): void
    {
        ToolRegistry::register(['slug' => 'po-checker', 'name' => 'PO Checker', 'url' => '/tools/po-checker']);
        $user = User::factory()->create();
        $user->givePermissionTo('tool.po-checker');

        ToolRegistry::unregister('po-checker');

        $this->assertDatabaseMissing('tools', ['slug' => 'po-checker']);
        $this->assertDatabaseMissing('permissions', ['name' => 'tool.po-checker']);
        $this->assertDatabaseMissing('model_has_permissions', ['model_id' => $user->id]);
    }

    public function test_the_launcher_groups_tools_in_order_and_puts_ungrouped_ones_under_tools(): void
    {
        $this->tool(['name' => 'PO Checker', 'slug' => 'po-checker', 'group' => 'Amazon · Purchase orders', 'icon' => 'box', 'url' => '/tools/po-checker', 'sort_order' => 2]);
        $this->tool(['name' => 'App Stats', 'slug' => 'app-stats', 'group' => 'BioSteel Teams', 'url' => '/tools/app-stats', 'sort_order' => 1]);
        $this->tool(['name' => 'Loose Tool', 'slug' => 'loose', 'url' => '/tools/loose', 'sort_order' => 3]);

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get('/dashboard')
            ->assertSeeInOrder(['All tools', 'BioSteel Teams', 'App Stats', 'Amazon · Purchase orders', 'PO Checker', 'Tools', 'Loose Tool'])
            ->assertSee('href="'.url('/tools/po-checker').'"', false);
    }

    public function test_the_launcher_says_so_when_the_user_has_no_tools(): void
    {
        $this->tool(['name' => 'PO Checker', 'slug' => 'po-checker', 'url' => '/tools/po-checker']);

        $this->actingAs(User::factory()->create()->assignRole('user'))
            ->get('/dashboard')
            ->assertSee('No tools yet')
            ->assertDontSee('PO Checker');
    }

    public function test_an_icon_name_draws_the_icon_and_anything_else_is_shown_as_text(): void
    {
        $this->tool(['name' => 'Named Icon', 'slug' => 'named', 'icon' => 'box', 'url' => '/tools/named']);
        $this->tool(['name' => 'Emoji Icon', 'slug' => 'emoji', 'icon' => '🧾', 'url' => '/tools/emoji']);

        $response = $this->actingAs(User::factory()->create()->assignRole('admin'))->get('/dashboard');

        $response->assertSee(Icons::path('box'), false);
        $response->assertSee('<span class="tool-emoji" aria-hidden="true">🧾</span>', false);
    }

    public function test_the_sidebar_lists_the_users_tools_by_group_and_marks_the_current_one(): void
    {
        $tool = $this->tool(['name' => 'PO Checker', 'slug' => 'po-checker', 'group' => 'Amazon · Purchase orders', 'url' => '/dashboard', 'sort_order' => 1]);
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo($tool->permissionName());

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertSeeInOrder(['aria-label="Tools"', 'Amazon · Purchase orders', 'aria-current="page"', 'PO Checker'], false);
    }

    public function test_only_admins_get_the_admin_group_in_the_sidebar(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get('/dashboard')
            ->assertSee('href="'.route('admin.tools.index').'"', false);

        $this->actingAs(User::factory()->create()->assignRole('user'))
            ->get('/dashboard')
            ->assertDontSee('href="'.route('admin.tools.index').'"', false);
    }

    public function test_admin_pages_are_for_admins_only(): void
    {
        $this->get(route('admin.tools.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create()->assignRole('user'))
            ->get(route('admin.tools.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('admin.tools.index'))
            ->assertOk();
    }

    public function test_admin_tools_lists_every_tool_with_keyboard_reorder_buttons_and_a_delete_dialog(): void
    {
        $tool = $this->tool(['name' => 'PO Checker', 'slug' => 'po-checker', 'url' => '/tools/po-checker', 'active' => false]);

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('admin.tools.index'))
            ->assertSee('PO Checker')
            ->assertSee('Inactive')
            ->assertSee('data-reorder-url="'.route('admin.tools.reorder').'"', false)
            ->assertSee('aria-label="Move PO Checker up"', false)
            ->assertSee('id="delete-tool-'.$tool->id.'"', false);
    }

    public function test_an_admin_can_set_the_group_when_creating_and_editing(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post(route('admin.tools.store'), ['name' => 'Invoicing', 'group' => 'Finance', 'active' => true, 'sort_order' => 0]);
        $tool = Tool::where('slug', 'invoicing')->sole();
        $this->assertSame('Finance', $tool->group);

        $this->actingAs($admin)->put(route('admin.tools.update', $tool), ['name' => 'Invoicing', 'group' => 'Money', 'active' => true, 'sort_order' => 0])
            ->assertRedirect(route('admin.tools.index'));
        $this->assertSame('Money', $tool->fresh()->group);
    }

    public function test_the_create_and_edit_forms_render(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $tool = $this->tool(['name' => 'PO Checker', 'slug' => 'po-checker', 'url' => '/tools/po-checker']);

        $this->actingAs($admin)->get(route('admin.tools.create'))->assertOk()->assertSee('name="group"', false);
        $this->actingAs($admin)->get(route('admin.tools.edit', $tool))->assertOk()->assertSee('value="PO Checker"', false);
    }
}
