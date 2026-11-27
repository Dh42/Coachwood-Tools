<?php

namespace Tests\Feature\Admin;

use App\Models\GoogleWorkspaceDomain;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ported from the old app one case at a time as each slice landed: tools
 * (3d), deactivation and the viewer (3e), invitations (3g). All four cases
 * are here now.
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

    /** Changed only to use a company address, which is all that can be invited now (decision 2026-11-27). */
    public function test_inviting_a_user_logs_exactly_one_activity(): void
    {
        Notification::fake();
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New Person',
            'email' => 'new-person@coachwoodgroup.com',
            'role' => 'user',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'admin',
            'description' => 'User invited',
            'causer_id' => $admin->id,
        ]);
    }

    public function test_deactivating_a_user_logs_activity_and_purges_sessions(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        \DB::table('sessions')->insert([
            'id' => 'a-session-id',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('test'),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)->patch(route('admin.users.deactivate', $target));

        $this->assertTrue($target->fresh()->isDisabled());
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'admin',
            'description' => 'Account deactivated',
            'causer_id' => $admin->id,
            'subject_id' => $target->id,
        ]);
    }

    public function test_activity_log_viewer_filters_by_log_name(): void
    {
        $admin = $this->admin();
        Tool::create(['name' => 'Invoicing', 'slug' => 'invoicing', 'active' => true, 'sort_order' => 0]);

        activity('auth')->log('Login succeeded');
        activity('admin')->log('Tool created');

        $response = $this->actingAs($admin)->get(route('admin.activity.index', ['log_name' => 'auth']));

        $response->assertOk();
        $response->assertSee('Login succeeded');
        $response->assertDontSee('Tool created');
    }
}
