<?php

namespace Tests\Feature\Auth;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Ported from the old app. Its three cases signed in with a password; sign-in
 * is Google only (decision 2026-11-27), so each now goes through the Google
 * callback. The activity-log rows asserted are the old app's, unchanged,
 * except the failed case: a refused Google sign-in logs "Login blocked -
 * domain not allowed", as the old Google controller did.
 */
class AccountDeactivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
    }

    private function googleReturns(string $id, string $email, ?string $hostedDomain): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake(['id' => $id, 'name' => 'Someone', 'email' => $email, 'hd' => $hostedDomain]));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_disabled_user_cannot_log_in_with_google(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@coachwoodgroup.com',
            'google_id' => 'google-1',
            'disabled_at' => now(),
        ]);
        $this->googleReturns('google-1', 'disabled@coachwoodgroup.com', 'coachwoodgroup.com');

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertSessionHasErrors('email');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'description' => 'Login blocked - account disabled',
            'causer_id' => $user->id,
        ]);
    }

    public function test_active_user_login_is_logged(): void
    {
        $user = User::factory()->create(['email' => 'active@coachwoodgroup.com', 'google_id' => 'google-2']);
        $this->googleReturns('google-2', 'active@coachwoodgroup.com', 'coachwoodgroup.com');

        $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'description' => 'Login succeeded',
            'causer_id' => $user->id,
        ]);
    }

    public function test_failed_google_login_is_logged(): void
    {
        $this->googleReturns('google-3', 'someone@gmail.com', null);

        $this->get('/auth/google/callback');

        $this->assertGuest();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'description' => 'Login blocked - domain not allowed',
        ]);
    }
}
