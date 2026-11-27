<?php

namespace Tests\Feature\Auth;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);

        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
        config(['app.initial_admin_email' => 'lesley@coachwoodgroup.com']);
    }

    protected function mockSocialiteUser(string $id, string $name, string $email, ?string $hostedDomain): void
    {
        $socialiteUser = SocialiteUser::fake([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'hd' => $hostedDomain,
        ]);

        $provider = \Mockery::mock();
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_new_gsuite_user_is_created_and_logged_in(): void
    {
        $this->mockSocialiteUser('google-1', 'Jane Doe', 'jane@coachwoodgroup.com', 'coachwoodgroup.com');

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));

        $user = User::where('email', 'jane@coachwoodgroup.com')->firstOrFail();
        $this->assertSame('google-1', $user->google_id);
        $this->assertNull($user->password);
        $this->assertTrue($user->hasRole('user'));
    }

    public function test_initial_admin_email_is_granted_the_admin_role_on_first_login(): void
    {
        $this->mockSocialiteUser('google-2', 'Lesley', 'lesley@coachwoodgroup.com', 'coachwoodgroup.com');

        $this->get('/auth/google/callback');

        $user = User::where('email', 'lesley@coachwoodgroup.com')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_users_outside_the_allowed_domain_are_rejected(): void
    {
        $this->mockSocialiteUser('google-3', 'Outsider', 'someone@gmail.com', 'gmail.com');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'someone@gmail.com']);
    }

    public function test_existing_password_account_is_linked_to_google_on_matching_email(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@coachwoodgroup.com',
            'google_id' => null,
        ])->assignRole('user');

        $this->mockSocialiteUser('google-4', 'Existing User', 'existing@coachwoodgroup.com', 'coachwoodgroup.com');

        $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-4', $user->fresh()->google_id);
    }

    public function test_disabled_user_is_rejected_on_google_login(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@coachwoodgroup.com',
            'google_id' => 'google-5',
            'disabled_at' => now(),
        ])->assignRole('user');

        $this->mockSocialiteUser('google-5', 'Disabled User', 'disabled@coachwoodgroup.com', 'coachwoodgroup.com');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
    }

    public function test_a_workspace_org_spanning_several_domains_accepts_login_from_any_of_them(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'biosteel.com']);

        $this->mockSocialiteUser('google-6', 'Second Domain User', 'person@biosteel.com', 'biosteel.com');

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));
        $this->assertTrue(User::where('email', 'person@biosteel.com')->firstOrFail()->hasRole('user'));
    }

    public function test_a_domain_outside_the_multi_domain_list_is_still_rejected(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'biosteel.com']);

        $this->mockSocialiteUser('google-7', 'Outsider', 'someone@othercompany.com', 'othercompany.com');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'someone@othercompany.com']);
    }

    public function test_redirect_hints_a_single_configured_domain_directly(): void
    {
        Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
        Socialite::shouldReceive('with')->with(['hd' => 'coachwoodgroup.com'])->once()->andReturnSelf();
        Socialite::shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/fake'));

        $this->get('/auth/google/redirect');
    }

    public function test_redirect_hints_any_workspace_account_when_multiple_domains_are_configured(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'biosteel.com']);

        Socialite::shouldReceive('driver')->with('google')->andReturnSelf();
        Socialite::shouldReceive('with')->with(['hd' => '*'])->once()->andReturnSelf();
        Socialite::shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/fake'));

        $this->get('/auth/google/redirect');
    }
}
