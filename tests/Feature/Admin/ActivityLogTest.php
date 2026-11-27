<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ported from the old app one case at a time, as each slice lands: the tool
 * case here (3d); invitations (3g), deactivation and the viewer (3e) later.
 * The cases are unchanged.
 */
class ActivityLogTest extends TestCase
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

    public function test_creating_a_tool_logs_exactly_one_activity(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.tools.store'), [
            'name' => 'Invoicing',
            'active' => true,
            'sort_order' => 0,
        ]);

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'admin',
            'description' => 'Tool created',
            'causer_id' => $admin->id,
        ]);
    }
}
