<?php

namespace Tests\Feature\Platform;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/** Sign-in pages and routing. Sign-in is Google only (decision 2026-11-27). */
class SignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_out_visitor_is_sent_from_the_root_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_sign_in_page_offers_google_and_nothing_else(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('href="'.route('google.redirect').'"', false)
            ->assertSee('Sign in with Google')
            ->assertDontSee('type="password"', false)
            ->assertDontSee('Forgot your password?');
    }

    public function test_the_sign_in_page_shows_why_google_sign_in_was_refused(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake(['id' => 'g-1', 'name' => 'Outsider', 'email' => 'someone@gmail.com', 'hd' => null]));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->followingRedirects()
            ->get('/auth/google/callback')
            ->assertSee('You must sign in with a coachwoodgroup.com account.')
            ->assertSee('role="alert"', false);
    }

    public function test_a_signed_in_user_is_sent_from_the_root_and_sign_in_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get('/login')->assertRedirect(route('dashboard'));
    }

    public function test_the_dashboard_and_profile_need_a_signed_in_user(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/profile')->assertRedirect(route('login'));
    }

    public function test_the_sidebar_links_to_the_profile_and_offers_log_out(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Sam Example']))
            ->get('/dashboard')
            ->assertSee('href="'.route('profile.edit').'"', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('Sam Example');
    }

    public function test_saving_the_profile_shows_a_readable_message(): void
    {
        $this->actingAs(User::factory()->create())
            ->followingRedirects()
            ->patch('/profile', ['name' => 'New Name'])
            ->assertSee('<template data-toast="good">Profile saved.</template>', false);
    }
}
