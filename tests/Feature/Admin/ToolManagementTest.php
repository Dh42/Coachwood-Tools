<?php

namespace Tests\Feature\Admin;

use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ToolManagementTest extends TestCase
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

    public function test_admin_can_create_a_tool_and_its_permission(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.tools.store'), [
            'name' => 'Invoicing',
            'active' => true,
            'sort_order' => 0,
        ]);

        $response->assertRedirect(route('admin.tools.index'));

        $tool = Tool::where('slug', 'invoicing')->firstOrFail();
        $this->assertTrue(Permission::where('name', $tool->permissionName())->exists());
    }

    public function test_admin_can_set_a_description_and_emoji_icon(): void
    {
        $this->actingAs($this->admin())->post(route('admin.tools.store'), [
            'name' => 'Invoicing',
            'description' => 'Send and track customer invoices.',
            'icon' => '🧾',
            'active' => true,
            'sort_order' => 0,
        ]);

        $tool = Tool::where('slug', 'invoicing')->firstOrFail();
        $this->assertSame('Send and track customer invoices.', $tool->description);
        $this->assertSame('🧾', $tool->icon);
    }

    public function test_creating_a_tool_with_a_colliding_slug_fails_validation_instead_of_erroring(): void
    {
        Tool::create(['name' => 'Invoicing', 'slug' => 'invoicing', 'active' => true, 'sort_order' => 0]);

        $response = $this->actingAs($this->admin())->post(route('admin.tools.store'), [
            'name' => 'Invoicing',
            'active' => true,
            'sort_order' => 0,
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Tool::where('slug', 'invoicing')->count());
    }

    public function test_creating_a_tool_with_a_colliding_url_fails_validation_instead_of_erroring(): void
    {
        Tool::create(['name' => 'Invoicing', 'slug' => 'invoicing', 'url' => '/tools/invoicing', 'active' => true, 'sort_order' => 0]);

        $response = $this->actingAs($this->admin())->post(route('admin.tools.store'), [
            'name' => 'Invoicing Two',
            'url' => '/tools/invoicing',
            'active' => true,
            'sort_order' => 0,
        ]);

        $response->assertSessionHasErrors('url');
        $this->assertSame(1, Tool::where('url', '/tools/invoicing')->count());
    }

    public function test_admin_can_delete_a_tool_which_revokes_it_from_everyone_who_had_it(): void
    {
        $tool = Tool::create(['name' => 'Invoicing', 'slug' => 'invoicing', 'active' => true, 'sort_order' => 0]);
        $permission = Permission::findOrCreate($tool->permissionName());

        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($permission);

        $response = $this->actingAs($this->admin())->delete(route('admin.tools.destroy', $tool));

        $response->assertRedirect(route('admin.tools.index'));
        $this->assertDatabaseMissing('tools', ['id' => $tool->id]);
        $this->assertDatabaseMissing('permissions', ['name' => 'tool.invoicing']);
        $this->assertDatabaseMissing('model_has_permissions', ['permission_id' => $permission->id]);
    }

    public function test_admin_can_reorder_tools_via_drag_and_drop(): void
    {
        $a = Tool::create(['name' => 'A', 'slug' => 'a', 'active' => true, 'sort_order' => 0]);
        $b = Tool::create(['name' => 'B', 'slug' => 'b', 'active' => true, 'sort_order' => 1]);
        $c = Tool::create(['name' => 'C', 'slug' => 'c', 'active' => true, 'sort_order' => 2]);

        // Dragged into C, A, B order.
        $response = $this->actingAs($this->admin())
            ->patchJson(route('admin.tools.reorder'), ['order' => [$c->id, $a->id, $b->id]]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
        $this->assertSame(0, $c->fresh()->sort_order);
    }

    public function test_reorder_rejects_an_id_that_doesnt_belong_to_any_tool(): void
    {
        $a = Tool::create(['name' => 'A', 'slug' => 'a', 'active' => true, 'sort_order' => 0]);

        $response = $this->actingAs($this->admin())
            ->patchJson(route('admin.tools.reorder'), ['order' => [$a->id, 999999]]);

        $response->assertStatus(422);
        $this->assertSame(0, $a->fresh()->sort_order); // unchanged — the whole request was rejected
    }

    public function test_reorder_rejects_a_duplicate_id(): void
    {
        $a = Tool::create(['name' => 'A', 'slug' => 'a', 'active' => true, 'sort_order' => 0]);
        $b = Tool::create(['name' => 'B', 'slug' => 'b', 'active' => true, 'sort_order' => 1]);

        $response = $this->actingAs($this->admin())
            ->patchJson(route('admin.tools.reorder'), ['order' => [$a->id, $a->id]]);

        $response->assertStatus(422);
        $this->assertSame(1, $b->fresh()->sort_order); // unchanged
    }

    public function test_non_admins_cannot_reorder_tools(): void
    {
        $a = Tool::create(['name' => 'A', 'slug' => 'a', 'active' => true, 'sort_order' => 0]);
        $b = Tool::create(['name' => 'B', 'slug' => 'b', 'active' => true, 'sort_order' => 1]);

        $user = User::factory()->create();
        $user->assignRole('user');

        $response = $this->actingAs($user)
            ->patchJson(route('admin.tools.reorder'), ['order' => [$b->id, $a->id]]);

        $response->assertForbidden();
        $this->assertSame(0, $a->fresh()->sort_order);
    }

    public function test_reordering_the_dashboard_visible_tool_order_changes_after_a_reorder(): void
    {
        $first = Tool::create(['name' => 'Alpha Tool', 'slug' => 'alpha-tool', 'active' => true, 'sort_order' => 0]);
        $second = Tool::create(['name' => 'Beta Tool', 'slug' => 'beta-tool', 'active' => true, 'sort_order' => 1]);
        Permission::findOrCreate($first->permissionName());
        Permission::findOrCreate($second->permissionName());

        $admin = $this->admin();
        $this->actingAs($admin)->patchJson(route('admin.tools.reorder'), ['order' => [$second->id, $first->id]]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertSeeInOrder(['Beta Tool', 'Alpha Tool']);
    }
}
