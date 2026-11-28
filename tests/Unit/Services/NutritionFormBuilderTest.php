<?php

namespace Tests\Unit\Services;

use App\Services\AmazonListings\NutritionFormBuilder;
use Tests\TestCase;

class NutritionFormBuilderTest extends TestCase
{
    private array $schemaProps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaProps = ['energy' => true, 'fat' => true, 'carbohydrate' => true, 'protein' => true];
    }

    public function test_all_blank_input_produces_just_the_marketplace_id(): void
    {
        $item = NutritionFormBuilder::build([], $this->schemaProps, 'A2EUQ1WTGCTBG2');

        $this->assertSame(['marketplace_id' => 'A2EUQ1WTGCTBG2'], $item);
    }

    public function test_energy_fat_carbohydrate_protein_are_wrapped_in_a_one_element_array(): void
    {
        $item = NutritionFormBuilder::build([
            'energy' => ['content' => '150'],
            'fat' => ['total' => '5'],
            'carbohydrate' => ['total' => '20'],
            'protein' => ['value' => '10'],
        ], $this->schemaProps, 'A2EUQ1WTGCTBG2');

        $this->assertSame([['content' => 150.0]], $item['energy']);
        $this->assertSame([['total' => 5.0]], $item['fat']);
        $this->assertSame([['total' => 20.0]], $item['carbohydrate']);
        $this->assertSame([['value' => 10.0]], $item['protein']);
    }

    public function test_a_group_missing_from_the_product_types_schema_is_skipped_even_if_posted(): void
    {
        $item = NutritionFormBuilder::build(['energy' => ['content' => '150']], ['fat' => true], 'A2EUQ1WTGCTBG2');

        $this->assertArrayNotHasKey('energy', $item);
    }

    public function test_vitamins_and_minerals_is_a_flat_list_not_wrapped_again(): void
    {
        $item = NutritionFormBuilder::build([
            'vitamins_and_minerals' => [
                ['nutrient' => 'VITAMIN_D', 'value' => '10', 'unit' => 'MICROGRAMS'],
                ['nutrient' => 'CALCIUM', 'value' => '200', 'unit' => 'MILLIGRAMS'],
            ],
        ], $this->schemaProps, 'A2EUQ1WTGCTBG2');

        $this->assertSame([
            ['nutrient' => 'VITAMIN_D', 'value' => 10.0, 'unit' => 'MICROGRAMS'],
            ['nutrient' => 'CALCIUM', 'value' => 200.0, 'unit' => 'MILLIGRAMS'],
        ], $item['vitamins_and_minerals']);
    }

    public function test_a_fully_blank_vitamin_row_is_dropped(): void
    {
        $item = NutritionFormBuilder::build([
            'vitamins_and_minerals' => [
                ['nutrient' => '', 'value' => ''],
                ['nutrient' => 'CALCIUM', 'value' => '200'],
            ],
        ], $this->schemaProps, 'A2EUQ1WTGCTBG2');

        $this->assertCount(1, $item['vitamins_and_minerals']);
    }

    public function test_serving_description_is_wrapped_with_a_hardcoded_en_ca_language_tag(): void
    {
        // Deliberately not channel-derived — confirmed live to be en_CA on
        // both the Vendor and Seller Central tools, not "fixed" to en_US
        // here since that would be a real behavior change, not a port.
        $item = NutritionFormBuilder::build(['serving_description' => ['value' => 'Per bottle']], $this->schemaProps, 'ATVPDKIKX0DER');

        $this->assertSame([['value' => 'Per bottle', 'language_tag' => 'en_CA']], $item['serving_description']);
    }

    public function test_blank_group_fields_are_excluded_but_present_ones_kept(): void
    {
        $item = NutritionFormBuilder::build([
            'energy' => ['content' => '150', 'unit' => ''],
        ], $this->schemaProps, 'A2EUQ1WTGCTBG2');

        $this->assertSame([['content' => 150.0]], $item['energy']);
    }
}
