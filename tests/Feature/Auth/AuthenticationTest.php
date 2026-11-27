<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from the old app. Sign-in is Google only (decision 2026-11-27), so
 * the two password cases are replaced by checks that the password routes
 * are gone. The screen and logout cases are unchanged.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_there_is_no_password_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertMethodNotAllowed();

        $this->assertGuest();
    }

    public function test_there_are_no_password_reset_or_change_routes(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'someone@example.com'])->assertNotFound();
        $this->get('/reset-password/token')->assertNotFound();
        $this->post('/reset-password')->assertNotFound();
        $this->get('/register')->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->put('/password')->assertNotFound();
        $this->get('/confirm-password')->assertNotFound();
        $this->get('/verify-email')->assertNotFound();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
