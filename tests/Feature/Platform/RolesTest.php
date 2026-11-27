<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_and_user_roles_come_from_a_migration(): void
    {
        $this->assertEqualsCanonicalizing(['admin', 'user'], Role::query()->where('guard_name', 'web')->pluck('name')->all());
    }

    public function test_an_admin_passes_every_permission_check(): void
    {
        Permission::findOrCreate('tool.example', 'web');
        $admin = User::factory()->create()->assignRole('admin');

        $this->assertTrue($admin->can('tool.example'));
        $this->assertTrue(Gate::forUser($admin)->allows('an-ability-nobody-defined'));
    }

    public function test_a_user_needs_the_permission_itself(): void
    {
        Permission::findOrCreate('tool.example', 'web');
        $user = User::factory()->create()->assignRole('user');

        $this->assertFalse($user->can('tool.example'));

        $user->givePermissionTo('tool.example');

        $this->assertTrue($user->fresh()->can('tool.example'));
    }

    public function test_the_user_model_reports_disabled_and_pending_accounts(): void
    {
        $this->assertTrue(User::factory()->make(['disabled_at' => now()])->isDisabled());
        $this->assertFalse(User::factory()->make()->isDisabled());

        $this->assertTrue(User::factory()->make(['password' => null, 'google_id' => null])->isPending());
        $this->assertFalse(User::factory()->make(['password' => null, 'google_id' => 'google-1'])->isPending());
        $this->assertFalse(User::factory()->make()->isPending());
    }
}
