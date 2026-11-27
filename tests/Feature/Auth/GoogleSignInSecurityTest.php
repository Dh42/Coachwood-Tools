<?php

namespace Tests\Feature\Auth;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Fixes to the old Google sign-in, each failing on the old code first
 * (docs/found-issues.md). Sign-in is Google only (decision 2026-11-27), so
 * these checks are the whole front door.
 */
class GoogleSignInSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
    }

    /** @param array<string, mixed> $raw */
    private function googleReturns(array $raw): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake($raw));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_with_no_domains_configured_nobody_gets_in(): void
    {
        GoogleWorkspaceDomain::query()->delete();
        $this->googleReturns(['id' => 'google-1', 'name' => 'Anyone', 'email' => 'anyone@gmail.com', 'hd' => null]);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'anyone@gmail.com']);
    }

    public function test_a_company_address_without_the_workspace_claim_is_refused(): void
    {
        // A consumer Google account can be registered on any address, a
        // company one included. Only Workspace accounts carry `hd`.
        $this->googleReturns(['id' => 'google-2', 'name' => 'Consumer', 'email' => 'someone@coachwoodgroup.com', 'hd' => null]);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'someone@coachwoodgroup.com']);
    }

    public function test_a_google_account_cannot_take_over_an_account_linked_to_a_different_google_id(): void
    {
        $owner = User::factory()->create(['email' => 'taken@coachwoodgroup.com', 'google_id' => 'google-owner', 'password' => null]);
        $this->googleReturns(['id' => 'google-other', 'name' => 'Other', 'email' => 'taken@coachwoodgroup.com', 'hd' => 'coachwoodgroup.com']);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame('google-owner', $owner->fresh()->google_id);
    }

    public function test_a_new_google_user_is_marked_verified(): void
    {
        $this->googleReturns(['id' => 'google-3', 'name' => 'New Person', 'email' => 'new@coachwoodgroup.com', 'hd' => 'coachwoodgroup.com']);

        $this->get('/auth/google/callback');

        $this->assertNotNull(User::where('email', 'new@coachwoodgroup.com')->firstOrFail()->email_verified_at);
    }

    public function test_a_disabled_user_is_signed_out_on_their_next_request(): void
    {
        $user = User::factory()->create(['google_id' => 'google-4']);
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['disabled_at' => now()])->save();

        $this->get('/dashboard')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_stale_google_response_goes_back_to_sign_in_with_a_message(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
