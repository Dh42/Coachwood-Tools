<?php

namespace Tests\Feature\Platform;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Tests\TestCase;

class ShellTest extends TestCase
{
    public function test_the_shell_defaults_to_midnight_and_normal_text(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-theme="midnight"', false)
            ->assertSee('data-font-size="normal"', false)
            ->assertSee('aria-label="Sidebar"', false)
            ->assertSee('Skip to content');
    }

    public function test_it_renders_the_theme_and_text_size_from_their_cookies(): void
    {
        $this->withUnencryptedCookies(['theme' => 'daylight', 'font_size' => 'large'])
            ->get('/')
            ->assertSee('data-theme="daylight"', false)
            ->assertSee('data-font-size="large"', false)
            ->assertSeeInOrder(['data-theme-option="daylight"', 'aria-pressed="true"'], false);
    }

    public function test_unknown_cookie_values_fall_back_to_the_defaults(): void
    {
        $this->withUnencryptedCookies(['theme' => '"><script>', 'font_size' => 'huge'])
            ->get('/')
            ->assertSee('data-theme="midnight"', false)
            ->assertSee('data-font-size="normal"', false)
            ->assertDontSee('"><script>', false);
    }

    public function test_the_preference_cookies_are_not_encrypted_so_javascript_can_set_them(): void
    {
        $middleware = app(EncryptCookies::class);

        $this->assertTrue($middleware->isDisabled('theme'));
        $this->assertTrue($middleware->isDisabled('font_size'));
    }

    public function test_the_sidebar_offers_all_four_themes_and_both_text_sizes(): void
    {
        $response = $this->get('/');

        foreach (['midnight', 'graphite', 'daylight', 'evergreen'] as $theme) {
            $response->assertSee('data-theme-option="'.$theme.'"', false);
        }
        $response->assertSee('data-font-size-option="normal"', false)->assertSee('data-font-size-option="large"', false);
    }

    public function test_home_marks_itself_as_the_current_page(): void
    {
        $this->get('/')->assertSeeInOrder(['class="nav-item"', 'aria-current="page"', 'Home'], false);
    }

    public function test_the_component_gallery_renders_every_component(): void
    {
        $this->get('/dev/components')
            ->assertOk()
            ->assertSee('data-table', false)
            ->assertSee('<dialog', false)
            ->assertSee('id="demo-chart"', false)
            ->assertSee('aria-busy="true"', false)
            ->assertSee('Provisional or partial data.')
            ->assertSee('Never updated');
    }

    public function test_session_flashes_become_toasts(): void
    {
        $this->withSession(['status' => 'Saved the sample.'])
            ->get('/')
            ->assertSee('<template data-toast="good">Saved the sample.</template>', false);
    }
}
