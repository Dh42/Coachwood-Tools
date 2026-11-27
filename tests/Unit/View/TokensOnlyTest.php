<?php

namespace Tests\Unit\View;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Build spec section 15, rule 1: every colour and size comes from the tokens
 * in resources/css/tokens.css. No view may hard-code a colour or a pixel size.
 */
class TokensOnlyTest extends TestCase
{
    public function test_no_view_hard_codes_a_colour_or_a_pixel_size(): void
    {
        $offences = [];
        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
                if (preg_match('/(?<![\w&])#[0-9a-fA-F]{3,8}\b(?![\w-])|\brgba?\(|\bhsla?\(|\b\d+(\.\d+)?px\b/', $line)) {
                    $offences[] = $file->getRelativePathname().':'.($n + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $offences, "Use a token instead:\n".implode("\n", $offences));
    }

    public function test_only_the_token_file_defines_theme_colours(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css, 'app.css must use the tokens in tokens.css.');
    }
}
