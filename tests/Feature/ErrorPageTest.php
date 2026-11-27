<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Not a real HTTP 500 (nothing here deliberately throws) — Laravel only
 * swaps in resources/views/errors/500.blade.php when APP_DEBUG is off,
 * which the test environment isn't. Rendering the view directly is enough
 * to guard the one thing that actually matters: it stays renderable
 * (doesn't depend on an authenticated session — <x-guest-layout> doesn't —
 * and doesn't reference anything that can go missing) and keeps asking for
 * the specific things staff currently leave out when they just screenshot
 * an error and say "there's a problem."
 */
class ErrorPageTest extends TestCase
{
    public function test_custom_500_page_renders_with_reporting_instructions(): void
    {
        $html = view('errors.500')->render();

        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringContainsString('step by step', $html);
        $this->assertStringContainsString('attach the actual file', $html);
        $this->assertStringContainsString('screenshot', $html);
        $this->assertStringContainsString('id="error-report-url"', $html);
        $this->assertStringContainsString('id="error-report-time"', $html);
    }
}
