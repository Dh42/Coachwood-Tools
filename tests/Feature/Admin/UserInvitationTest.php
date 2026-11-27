<?php

namespace Tests\Feature\Admin;

use App\Models\GoogleWorkspaceDomain;
use App\Models\Team;
use App\Models\Tool;
use App\Models\User;
use App\Notifications\GoogleSignInInvitationNotification;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ported from the old app. Sign-in is Google only (decision 2026-11-27), so
 * only company-domain addresses can be invited, and every invitation is the
 * "Sign in with Google" email. Cases changed for that say so; the rest are
 * unchanged. UserInvitationNotification (the old password link) no longer
 * exists; the assertions that it was not sent are kept as they were.
 */
class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);

        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /** Changed: was an external address with a password link; now a colleague with the Google email. */
    public function test_admin_can_invite_a_colleague_with_tools_and_teams(): void
    {
        Notification::fake();

        $tool = Tool::create(['name' => 'Invoicing', 'slug' => 'invoicing', 'active' => true, 'sort_order' => 0]);
        Permission::findOrCreate($tool->permissionName());

        $team = Team::create(['name' => 'Amazon']);

        $response = $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'New Person',
            'email' => 'new-person@coachwoodgroup.com',
            'role' => 'user',
            'tools' => ['invoicing'],
            'teams' => [$team->id],
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'new-person@coachwoodgroup.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNull($user->google_id);
        $this->assertTrue($user->isPending());
        $this->assertTrue($user->hasRole('user'));
        $this->assertTrue($user->can($tool->permissionName()));
        $this->assertTrue($user->teams->pluck('id')->contains($team->id));

        Notification::assertSentTo($user, GoogleSignInInvitationNotification::class);
    }

    /** New: replaces the external invitation. Such a person could never sign in. */
    public function test_an_address_outside_the_company_domains_cannot_be_invited(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Outside Person',
            'email' => 'someone@example.com',
            'role' => 'user',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
        Notification::assertNothingSent();
    }

    public function test_admin_inviting_a_company_domain_user_sends_a_google_sign_in_email_not_a_password_link(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'New Colleague',
            'email' => 'new-colleague@coachwoodgroup.com',
            'role' => 'user',
            'tools' => [],
            'teams' => [],
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'new-colleague@coachwoodgroup.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNull($user->google_id);
        $this->assertTrue($user->isPending());

        Notification::assertSentTo($user, GoogleSignInInvitationNotification::class);
        Notification::assertNotSentTo($user, UserInvitationNotification::class);
    }

    public function test_admin_inviting_a_second_workspace_domain_still_sends_the_google_sign_in_email(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'biosteel.com']);
        Notification::fake();

        $response = $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Second Domain Colleague',
            'email' => 'new-colleague@biosteel.com',
            'role' => 'user',
            'tools' => [],
            'teams' => [],
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'new-colleague@biosteel.com')->firstOrFail();
        Notification::assertSentTo($user, GoogleSignInInvitationNotification::class);
        Notification::assertNotSentTo($user, UserInvitationNotification::class);
    }

    public function test_resend_invitation_sends_the_google_sign_in_email_for_a_company_domain_pending_user(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $pendingUser = User::factory()->create([
            'email' => 'pending@coachwoodgroup.com', 'password' => null, 'google_id' => null,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.users.resend-invitation', $pendingUser));
        $response->assertRedirect(route('admin.users.index'));

        Notification::assertSentTo($pendingUser, GoogleSignInInvitationNotification::class);
        Notification::assertNotSentTo($pendingUser, UserInvitationNotification::class);
    }

    /**
     * Changed: the pending user is on a company domain and gets the Google
     * email; there is no token to regenerate. "Set a password via the
     * emailed link" was removed with password sign-in.
     */
    public function test_resend_invitation_sends_again_and_is_forbidden_once_active(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $pendingUser = User::factory()->create(['email' => 'waiting@coachwoodgroup.com', 'password' => null, 'google_id' => null]);

        $response = $this->actingAs($admin)->post(route('admin.users.resend-invitation', $pendingUser));
        $response->assertRedirect(route('admin.users.index'));

        Notification::assertSentTo($pendingUser, GoogleSignInInvitationNotification::class);

        $activeUser = User::factory()->create();
        $forbidden = $this->actingAs($admin)->post(route('admin.users.resend-invitation', $activeUser));
        $forbidden->assertForbidden();
    }

    /**
     * Real production symptom this reproduces: Gmail isn't connected yet
     * (Admin > Mail Settings), so GmailApiTransport::accessToken() throws.
     * Before this fix that propagated straight up into a raw 500 — with the
     * user record already committed (created earlier in the same request),
     * so the only way to notice anything went wrong was a blank 500 page,
     * and resubmitting the same form just hit "email already taken" instead
     * of retrying the send.
     */
    /** Changed only to use a company address, which is all that can be invited now. */
    public function test_a_failed_invitation_email_does_not_500_and_leaves_a_recoverable_pending_user(): void
    {
        config(['mail.default' => 'gmail_api']); // no GmailConnection row exists — accessToken() throws

        $response = $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'New Person',
            'email' => 'new-person@coachwoodgroup.com',
            'role' => 'user',
            'tools' => [],
            'teams' => [],
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Mail Settings', session('error'));

        // The user was still created and is recoverable via Resend Invite —
        // not lost, and not blocking a re-submission with a confusing
        // "already taken" error instead of a real retry.
        $user = User::where('email', 'new-person@coachwoodgroup.com')->firstOrFail();
        $this->assertTrue($user->isPending());

        $this->actingAs($this->admin())->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('failed to send', false);
    }

    public function test_resend_invitation_also_surfaces_a_send_failure_instead_of_500ing(): void
    {
        config(['mail.default' => 'gmail_api']);

        $admin = $this->admin();
        $pendingUser = User::factory()->create(['email' => 'pending@coachwoodgroup.com', 'password' => null, 'google_id' => null]);

        $response = $this->actingAs($admin)->post(route('admin.users.resend-invitation', $pendingUser));

        $response->assertRedirect(route('admin.users.index'));
        $response->assertSessionHas('error');
    }

    /**
     * Regression for a real production incident: a new tool's registration
     * migration inserts its permission via DB::table()->insertOrIgnore()
     * (the pattern every tool-registration migration in this app uses),
     * which bypasses Eloquent entirely — so Spatie's own auto-clear-on-
     * write never fires, and the 24h-cached permission list doesn't know
     * the new permission exists. Reproduces the exact sequence: cache gets
     * warmed by an earlier request, then a new permission is registered the
     * same way every tool-registration migration does. Confirms this really
     * does throw without the fix, and that a single forgetCachedPermissions()
     * call (now part of every tool-registration migration's own template —
     * see README's "Adding a new tool") is what actually prevents it.
     */
    public function test_a_permission_added_via_raw_insert_is_unusable_until_the_permission_cache_is_cleared(): void
    {
        Permission::findOrCreate('tool.something-older', 'web');
        app(PermissionRegistrar::class)->getPermissions(); // warms the cache, matching a real prior request in production

        DB::table('permissions')->insertOrIgnore([[
            'name' => 'tool.brand-new', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]]);

        $user = User::factory()->create();

        $this->expectException(PermissionDoesNotExist::class);
        $user->givePermissionTo('tool.brand-new');
    }

    public function test_clearing_the_permission_cache_after_the_same_raw_insert_makes_it_immediately_usable(): void
    {
        Permission::findOrCreate('tool.something-older', 'web');
        app(PermissionRegistrar::class)->getPermissions();

        DB::table('permissions')->insertOrIgnore([[
            'name' => 'tool.brand-new', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]]);
        app(PermissionRegistrar::class)->forgetCachedPermissions(); // the fix — one line, now in every registration migration

        $user = User::factory()->create();
        $user->givePermissionTo('tool.brand-new');

        $this->assertTrue($user->fresh()->can('tool.brand-new'));
    }

    /** New: an imported external account that never signed in can't sign in now, so there's nothing to resend. */
    public function test_resending_to_an_address_outside_the_company_domains_explains_why_not(): void
    {
        Notification::fake();
        $pendingUser = User::factory()->create(['email' => 'outside@example.com', 'password' => null, 'google_id' => null]);

        $this->actingAs($this->admin())->post(route('admin.users.resend-invitation', $pendingUser))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_non_admin_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.tools.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.teams.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.activity.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.mail.show'))->assertForbidden();
    }
}
