<?php

namespace Tests\Unit\Support;

use App\Support\ExternalWrites;
use App\Support\ExternalWritesDisabled;
use Tests\TestCase;

class ExternalWritesTest extends TestCase
{
    public function test_writes_are_off_in_the_test_environment(): void
    {
        $this->assertFalse(ExternalWrites::allowed());
    }

    public function test_ensure_allowed_throws_and_names_the_action_while_off(): void
    {
        config(['tools.external_writes' => false]);

        try {
            ExternalWrites::ensureAllowed('Shopify refundCreate');
            $this->fail('Expected ExternalWritesDisabled.');
        } catch (ExternalWritesDisabled $e) {
            $this->assertSame('Shopify refundCreate', $e->action);
            $this->assertStringContainsString('TOOLS_EXTERNAL_WRITES=false', $e->getMessage());
        }
    }

    public function test_ensure_allowed_passes_when_switched_on(): void
    {
        config(['tools.external_writes' => true]);

        ExternalWrites::ensureAllowed('Shopify refundCreate');

        $this->assertTrue(ExternalWrites::allowed());
    }

    public function test_only_a_real_true_switches_writes_on(): void
    {
        foreach (['true', '1', 1, 'yes', null] as $value) {
            config(['tools.external_writes' => $value]);
            $this->assertFalse(ExternalWrites::allowed(), 'Value: '.var_export($value, true));
        }
    }
}
