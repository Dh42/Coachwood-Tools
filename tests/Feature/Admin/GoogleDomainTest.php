<?php

namespace Tests\Feature\Admin;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use App\Support\GoogleWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Ported from the old app. One case changed on purpose; see its comment. */
class GoogleDomainTest extends TestCase
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

    public function test_admin_can_view_configured_domains(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $response = $this->actingAs($this->admin())->get(route('admin.google-domains.index'));

        $response->assertOk();
        $response->assertSee('coachwoodgroup.com');
    }

    /**
     * Changed on purpose. The old empty state warned that any Google account
     * was accepted. Since found issue 28 (fixed in 3b), no domain means
     * nobody can sign in, so the warning says that instead.
     */
    public function test_empty_state_warns_that_nobody_can_sign_in(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.google-domains.index'));

        $response->assertOk();
        $response->assertSee('Nobody can sign in with Google', false);
    }

    public function test_admin_can_add_a_domain(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.google-domains.store'), [
            'domain' => 'BioSteel.com',
        ]);

        $response->assertRedirect(route('admin.google-domains.index'));
        $this->assertDatabaseHas('google_workspace_domains', ['domain' => 'biosteel.com']);
    }

    public function test_a_duplicate_domain_is_rejected_regardless_of_case(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $response = $this->actingAs($this->admin())->post(route('admin.google-domains.store'), [
            'domain' => 'Coachwoodgroup.com',
        ]);

        $response->assertSessionHasErrors('domain');
        $this->assertSame(1, GoogleWorkspaceDomain::count());
    }

    public function test_an_invalid_domain_is_rejected(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.google-domains.store'), [
            'domain' => 'not a domain',
        ]);

        $response->assertSessionHasErrors('domain');
        $this->assertSame(0, GoogleWorkspaceDomain::count());
    }

    public function test_admin_can_remove_a_domain(): void
    {
        $domain = GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $response = $this->actingAs($this->admin())->delete(route('admin.google-domains.destroy', $domain));

        $response->assertRedirect(route('admin.google-domains.index'));
        $this->assertDatabaseMissing('google_workspace_domains', ['id' => $domain->id]);
    }

    public function test_removing_a_domain_takes_effect_immediately_for_login(): void
    {
        $domain = GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $this->assertTrue(GoogleWorkspace::isCompanyDomain('person@coachwoodgroup.com'));

        $this->actingAs($this->admin())->delete(route('admin.google-domains.destroy', $domain));

        $this->assertFalse(GoogleWorkspace::isCompanyDomain('person@coachwoodgroup.com'));
    }

    public function test_non_admin_cannot_manage_domains(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('admin.google-domains.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.google-domains.store'), ['domain' => 'example.com'])->assertForbidden();
    }
}
