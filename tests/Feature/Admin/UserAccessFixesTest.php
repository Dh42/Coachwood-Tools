<?php

namespace Tests\Feature\Admin;

use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fixes to the old Admin > Users, each failing on the old code first
 * (docs/found-issues.md).
 */
class UserAccessFixesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function tool(string $slug, bool $active = true): Tool
    {
        $tool = Tool::create(['name' => ucfirst($slug), 'slug' => $slug, 'active' => $active, 'sort_order' => 0]);
        Permission::findOrCreate($tool->permissionName(), 'web');

        return $tool;
    }

    /**
     * Issue 6 (Lesley, 2026-11-28: list every tool, inactive ones marked;
     * leave other permissions alone). This case passed on the old code too;
     * there the form never offered the inactive tool, so it was dropped.
     */
    public function test_saving_a_user_with_an_inactive_tool_ticked_keeps_that_access(): void
    {
        $this->tool('live');
        $paused = $this->tool('paused', active: false);
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo(['tool.live', 'tool.paused']);

        $this->actingAs($this->admin())->put(route('admin.users.update', $user), ['role' => 'user', 'tools' => ['live', 'paused']]);

        $this->assertTrue($user->fresh()->hasDirectPermission('tool.paused'));
        $paused->update(['active' => true]);
        $this->assertTrue($user->fresh()->can('tool.paused'));
    }

    /** Issue 6. */
    public function test_the_edit_form_lists_inactive_tools_marked_as_inactive(): void
    {
        $this->tool('paused', active: false);
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo('tool.paused');

        $this->actingAs($this->admin())->get(route('admin.users.edit', $user))
            ->assertSeeInOrder(['value="paused"', 'checked', 'Paused', 'Inactive'], false);
    }

    /** Issue 6. */
    public function test_saving_a_user_never_removes_a_permission_that_is_not_a_tool(): void
    {
        $this->tool('live');
        Permission::findOrCreate('reports.export', 'web');
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo(['tool.live', 'reports.export']);

        $this->actingAs($this->admin())->put(route('admin.users.update', $user), ['role' => 'user', 'tools' => []]);

        $user = $user->fresh();
        $this->assertFalse($user->hasDirectPermission('tool.live'));
        $this->assertTrue($user->hasDirectPermission('reports.export'));
    }

    /** Issue 7. */
    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.users.deactivate', $admin))->assertForbidden();

        $this->assertFalse($admin->fresh()->isDisabled());
    }

    /** Issue 7. */
    public function test_an_admin_cannot_remove_their_own_admin_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), ['role' => 'user', 'tools' => []])
            ->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }
}
