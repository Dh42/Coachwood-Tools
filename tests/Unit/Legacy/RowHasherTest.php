<?php

namespace Tests\Unit\Legacy;

use App\Legacy\RowHasher;
use PHPUnit\Framework\TestCase;

/**
 * Found issue 34: RowHasher cut every text value to 19 characters (meant
 * for date-times), so the compare:* commands couldn't see a difference
 * after a text value's 19th character.
 */
class RowHasherTest extends TestCase
{
    public function test_text_that_differs_after_19_characters_hashes_differently(): void
    {
        $a = RowHasher::normalise(['title' => 'Made-up listing title, version one']);
        $b = RowHasher::normalise(['title' => 'Made-up listing title, version two']);

        $this->assertNotSame(RowHasher::hash([$a]), RowHasher::hash([$b]));
    }

    public function test_date_times_still_compare_to_the_second(): void
    {
        $this->assertSame(
            RowHasher::normalise(['at' => '2026-10-01 12:00:00']),
            RowHasher::normalise(['at' => '2026-10-01 12:00:00.000000']),
        );
        $this->assertSame(['at' => '2026-10-01T12:00:00'], RowHasher::normalise(['at' => '2026-10-01T12:00:00+00:00']));
    }

    public function test_numbers_and_md5s_are_unchanged_in_behaviour(): void
    {
        $this->assertSame(['n' => '3.5000', 'h' => str_repeat('a', 32)], RowHasher::normalise(['n' => '3.5', 'h' => str_repeat('a', 32)]));
    }
}
