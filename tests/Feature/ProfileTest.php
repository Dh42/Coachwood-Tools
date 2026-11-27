<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from the old app. Sign-in is Google only (decision 2026-11-27):
 * - the email comes from Google and can't be changed here (found issue 8),
 *   so the update and verification cases now check that;
 * - self-delete is removed (found issue 8; admins manage accounts), so the
 *   three delete cases become one that checks the route is gone;
 * - the Google-only notice case is unchanged and now applies to everyone.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertSame('Test User', $user->refresh()->name);
    }

    public function test_the_email_address_cannot_be_changed_from_the_profile(): void
    {
        $user = User::factory()->create(['email' => 'me@coachwoodgroup.com']);

        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Test User', 'email' => 'colleague@coachwoodgroup.com'])
            ->assertRedirect('/profile');

        $this->assertSame('me@coachwoodgroup.com', $user->refresh()->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_users_cannot_delete_their_own_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/profile')->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
    }

    public function test_google_only_user_does_not_see_the_password_form(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'google_id' => 'google-1',
        ]);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $response->assertSee('Your password is managed by your Google account.');
        $response->assertDontSee('Current Password');
    }

    public function test_a_user_who_still_has_an_old_password_does_not_see_the_password_form_either(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertSee('Your password is managed by your Google account.')
            ->assertDontSee('Current Password')
            ->assertDontSee('Delete Account');
    }
}
