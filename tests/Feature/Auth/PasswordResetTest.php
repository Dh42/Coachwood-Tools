<?php

namespace Tests\Feature\Auth;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_a_company_domain_email_is_refused_a_reset_link_instead_of_being_sent_one(): void
    {
        Notification::fake();

        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $user = User::factory()->create(['email' => 'colleague@coachwoodgroup.com']);

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertSessionHasErrors('email');
        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_a_company_domain_email_cannot_set_a_password_even_with_a_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'colleague@coachwoodgroup.com', 'password' => null, 'google_id' => null]);
        $token = Password::broker()->createToken($user);

        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertNull($user->fresh()->password);
    }

    public function test_a_second_workspace_domain_is_also_refused_a_reset_link(): void
    {
        Notification::fake();

        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
        GoogleWorkspaceDomain::create(['domain' => 'biosteel.com']);

        $user = User::factory()->create(['email' => 'colleague@biosteel.com']);

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertSessionHasErrors('email');
        Notification::assertNotSentTo($user, ResetPassword::class);
    }
}
