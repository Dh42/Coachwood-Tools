<?php

namespace Tests\Unit\Amazon\Probe;

use App\Amazon\Probe\FieldStats;
use PHPUnit\Framework\TestCase;

class FieldStatsTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['asin' => 'B0TESTASIN', 'sourceableROOS' => 0.12, 'units' => 40, 'cost' => ['amount' => 123.45, 'currencyCode' => 'CAD'], 'startDate' => '2026-10-25', 'flag' => true],
            ['asin' => 'B0OTHERSIN', 'sourceableROOS' => 0.5, 'units' => 0, 'cost' => ['amount' => -7.5, 'currencyCode' => 'CAD'], 'startDate' => '2026-11-15', 'flag' => false],
            ['asin' => 'B0THIRDASN', 'sourceableROOS' => null, 'units' => 3],
        ];
    }

    public function test_it_never_outputs_identifiers_or_amounts(): void
    {
        $json = json_encode(FieldStats::describe($this->rows()));

        foreach (['B0TESTASIN', 'B0OTHERSIN', '123.45', '-7.5', '40'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_ratio_fields_get_a_range_and_amounts_do_not(): void
    {
        $stats = FieldStats::describe($this->rows());

        $this->assertSame([0.12, 0.5], $stats['sourceableROOS']['range']);
        $this->assertArrayNotHasKey('range', $stats['cost.amount']);
        $this->assertArrayNotHasKey('range', $stats['units']);
        $this->assertTrue($stats['cost.amount']['has_negative']);
    }

    public function test_empty_rate_counts_missing_and_null_values(): void
    {
        $stats = FieldStats::describe($this->rows());

        $this->assertSame(round(1 / 3, 4), $stats['sourceableROOS']['empty_rate']);
        $this->assertSame(round(1 / 3, 4), $stats['cost.amount']['empty_rate']);
        $this->assertSame(0.0, $stats['units']['empty_rate']);
        $this->assertSame(round(1 / 3, 4), $stats['units']['zero_rate']);
    }

    public function test_codes_are_counted_dates_ranged_and_other_strings_reduced_to_a_count(): void
    {
        $stats = FieldStats::describe($this->rows());

        $this->assertSame(['CAD' => 2], $stats['cost.currencyCode']['values']);
        $this->assertSame(['2026-10-25', '2026-11-15'], $stats['startDate']['date_range']);
        $this->assertSame('3', $stats['asin']['distinct']);
        $this->assertSame(['true' => 1, 'false' => 1], $stats['flag']['bools']);
    }

    public function test_lists_of_objects_are_described_per_element(): void
    {
        $stats = FieldStats::describe([
            ['forecasting' => [['weekNumber' => 1, 'demand' => ['mean' => 10]], ['weekNumber' => 2, 'demand' => ['mean' => null]]]],
            ['forecasting' => []],
        ]);

        $this->assertSame([2, 2], $stats['forecasting']['list_length']);
        $this->assertSame(0.5, $stats['forecasting']['empty_rate']);
        $this->assertSame(2, $stats['forecasting[].weekNumber']['non_empty']);
        $this->assertSame(0.5, $stats['forecasting[].demand.mean']['empty_rate']);
    }
}
