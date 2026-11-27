<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sign-in behaviour new in, or not covered by, the ported Breeze tests. */
class SignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_out_visitor_gets_the_sign_in_page_at_the_root(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Sign in to access your')
            ->assertSee('href="'.route('login').'"', false);
    }

    public function test_a_signed_in_user_is_sent_from_the_root_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_the_dashboard_and_profile_need_a_signed_in_user(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/profile')->assertRedirect(route('login'));
    }

    public function test_there_is_no_registration_route(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_a_disabled_user_cannot_sign_in_with_the_right_password(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'This account has been disabled. Contact an administrator.']);

        $this->assertGuest();
    }

    public function test_a_google_only_user_cannot_sign_in_with_a_password(): void
    {
        $user = User::factory()->create(['password' => null, 'google_id' => 'google-1']);

        $this->post('/login', ['email' => $user->email, 'password' => ''])->assertSessionHasErrors('password');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_sign_in_locks_out_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
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
        $user = User::factory()->create();

        $this->actingAs($user)
            ->followingRedirects()
            ->patch('/profile', ['name' => 'New Name', 'email' => $user->email])
            ->assertSee('<template data-toast="good">Profile saved.</template>', false);
    }

    public function test_a_wrong_password_reopens_the_delete_account_dialog(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->followingRedirects()
            ->delete('/profile', ['password' => 'wrong-password'])
            ->assertSee('data-modal-show', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_the_profile_page_does_not_open_the_delete_dialog_by_itself(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertDontSee('data-modal-show', false);
    }
}
