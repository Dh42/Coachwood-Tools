<?php

namespace Tests\Unit\View;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ComponentsTest extends TestCase
{
    public function test_a_status_badge_always_carries_its_label_and_an_icon(): void
    {
        $html = Blade::render('<x-status-badge tone="bad">Below cost</x-status-badge>');

        $this->assertStringContainsString('tone tone-bad', $html);
        $this->assertStringContainsString('Below cost', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    public function test_a_stat_tile_without_a_value_says_no_data_instead_of_zero(): void
    {
        $html = Blade::render('<x-stat-tile label="Units" :value="null" />');

        $this->assertStringContainsString('No data', $html);
        $this->assertStringNotContainsString('>0<', $html);
    }

    public function test_a_stat_tile_keeps_a_real_zero(): void
    {
        $this->assertStringContainsString('>0</div>', Blade::render('<x-stat-tile label="Units" :value="0" />'));
    }

    public function test_freshness_shows_the_period_and_flags_stale_data(): void
    {
        Carbon::setTestNow('2026-11-27 12:00:00');

        $fresh = Blade::render('<x-freshness-badge :updated-at="$u" :from="$f" :to="$t" :stale-after-hours="26" />', [
            'u' => now()->subHours(2), 'f' => '2026-11-01', 't' => '2026-11-26',
        ]);
        $this->assertStringContainsString('Nov 1, 2026 – Nov 26, 2026', $fresh);
        $this->assertStringContainsString('tone-good', $fresh);
        $this->assertStringContainsString('Updated 2 hours ago', $fresh);

        $stale = Blade::render('<x-freshness-badge :updated-at="$u" :stale-after-hours="26" />', ['u' => now()->subDays(3)]);
        $this->assertStringContainsString('tone-warn', $stale);
        $this->assertStringContainsString('Stale', $stale);

        $this->assertStringContainsString('Never updated', Blade::render('<x-freshness-badge />'));
    }

    public function test_data_state_renders_each_of_the_five_states(): void
    {
        $render = fn (string $state) => Blade::render('<x-data-state :state="$s" empty="No POs yet.">CONTENT</x-data-state>', ['s' => $state]);

        $this->assertStringContainsString('aria-busy="true"', $render('loading'));
        $this->assertStringNotContainsString('CONTENT', $render('loading'));
        $this->assertStringContainsString('No POs yet.', $render('empty'));
        $this->assertStringContainsString('role="alert"', $render('error'));
        $this->assertStringNotContainsString('CONTENT', $render('error'));
        $this->assertStringContainsString('Provisional or partial data.', $render('partial'));
        $this->assertStringContainsString('CONTENT', $render('partial'));
        $this->assertSame('CONTENT', trim($render('complete')));
    }

    public function test_tabs_mark_the_active_tab_and_keep_other_query_values(): void
    {
        $this->app['request']->query->set('from', '2026-11-01');

        $html = Blade::render('<x-tabs :tabs="[\'a\' => \'Alpha\', \'b\' => \'Beta\']" active="b" />');

        $this->assertMatchesRegularExpression('/href="[^"]*from=2026-11-01[^"]*tab=b"\s+aria-current="page"\s*>Beta/', $html);
        $this->assertStringNotContainsString('aria-current="page" >Alpha', $html);
    }

    public function test_the_data_table_offers_filter_csv_and_copy_when_asked(): void
    {
        $html = Blade::render('<x-data-table csv="x.csv" copy="asin"><thead><tr><x-th sort column="asin">ASIN</x-th></tr></thead><tbody></tbody></x-data-table>');

        $this->assertStringContainsString('data-table-filter', $html);
        $this->assertStringContainsString('data-filename="x.csv"', $html);
        $this->assertStringContainsString('data-column="asin"', $html);
        $this->assertStringContainsString('class="sort-btn"', $html);
    }

    public function test_icons_are_hidden_from_screen_readers_unless_labelled(): void
    {
        $this->assertStringContainsString('aria-hidden="true"', Blade::render('<x-icon name="home" />'));
        $this->assertStringContainsString('aria-label="Home"', Blade::render('<x-icon name="home" label="Home" />'));
    }

    public function test_a_form_field_links_its_label_and_shows_its_error_with_an_icon(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['email' => ['The email field is required.']]));

        view()->share('errors', $errors);
        $html = Blade::render('<x-form-field name="email" label="Email" type="email" />');

        $this->assertStringContainsString('<label for="email">Email</label>', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="email-error"', $html);
        $this->assertStringContainsString('The email field is required.', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    public function test_a_form_field_reads_a_named_error_bag_only(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['password' => ['Wrong.']]));

        view()->share('errors', $errors);
        $html = Blade::render('<x-form-field name="password" label="Password" type="password" bag="userDeletion" />');

        $this->assertStringNotContainsString('Wrong.', $html);
        $this->assertStringNotContainsString('aria-invalid', $html);
    }
}
