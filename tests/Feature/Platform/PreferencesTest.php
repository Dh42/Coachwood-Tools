<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_can_save_their_theme_and_text_size(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/preferences', ['theme' => 'evergreen'])->assertNoContent();
        $this->actingAs($user)->putJson('/preferences', ['font_size' => 'large'])->assertNoContent();

        $this->assertSame('evergreen', $user->fresh()->theme);
        $this->assertSame('large', $user->fresh()->font_size);
    }

    public function test_unknown_values_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/preferences', ['theme' => 'neon', 'font_size' => 'huge'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['theme', 'font_size']);

        $this->assertNull($user->fresh()->theme);
    }

    public function test_a_signed_out_visitor_cannot_save_preferences(): void
    {
        $this->putJson('/preferences', ['theme' => 'daylight'])->assertUnauthorized();
    }

    public function test_the_saved_choice_wins_over_the_cookie(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['theme' => 'graphite', 'font_size' => 'large'])->save();

        $this->actingAs($user)
            ->withUnencryptedCookies(['theme' => 'daylight', 'font_size' => 'normal'])
            ->get('/dashboard')
            ->assertSee('data-theme="graphite"', false)
            ->assertSee('data-font-size="large"', false);
    }

    public function test_the_cookie_is_used_until_the_user_picks_a_theme(): void
    {
        $this->actingAs(User::factory()->create())
            ->withUnencryptedCookies(['theme' => 'daylight'])
            ->get('/dashboard')
            ->assertSee('data-theme="daylight"', false);
    }

    public function test_a_bad_saved_value_falls_back_to_the_cookie(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['theme' => 'removed-theme'])->save();

        $this->actingAs($user)
            ->withUnencryptedCookies(['theme' => 'daylight'])
            ->get('/dashboard')
            ->assertSee('data-theme="daylight"', false);
    }

    public function test_only_signed_in_pages_tell_the_script_where_to_save(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee('data-preferences-url="'.route('preferences.update').'"', false);

        $this->app['auth']->forgetGuards();

        $this->get('/login')->assertDontSee('data-preferences-url', false);
    }
}
