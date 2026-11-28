<?php

namespace Tests\Unit\Services;

use App\Services\AmazonListings\AttributeEngine;
use Tests\TestCase;

class AttributeEngineTest extends TestCase
{
    public function test_leaf_paths_recurses_and_skips_marketplace_id(): void
    {
        $item = [
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'value' => 'Hello',
            'language_tag' => 'en_CA',
            'nested' => ['a' => 1, 'b' => ['c' => 2]],
        ];

        $leaves = AttributeEngine::leafPaths($item);
        $paths = array_map(fn ($l) => implode('.', $l[0]), $leaves);

        $this->assertNotContains('marketplace_id', $paths);
        $this->assertContains('value', $paths);
        $this->assertContains('nested.a', $paths);
        $this->assertContains('nested.b.c', $paths);
    }

    public function test_get_and_set_path_round_trip(): void
    {
        $item = ['a' => ['b' => 'original']];

        $this->assertSame('original', AttributeEngine::getPath($item, ['a', 'b']));
        $this->assertNull(AttributeEngine::getPath($item, ['a', 'missing']));

        AttributeEngine::setPath($item, ['a', 'b'], 'updated');
        $this->assertSame('updated', $item['a']['b']);
    }

    public function test_set_path_auto_vivifies_missing_intermediate_arrays(): void
    {
        $item = [];
        AttributeEngine::setPath($item, ['x', 'y', 'z'], 'value');

        $this->assertSame('value', $item['x']['y']['z']);
    }

    public function test_canonicalize_sorts_associative_arrays_but_preserves_list_order(): void
    {
        $a = ['b' => 1, 'a' => 2];
        $b = ['a' => 2, 'b' => 1];

        $this->assertTrue(AttributeEngine::sameValue($a, $b));

        $list1 = [['x' => 1], ['x' => 2]];
        $list2 = [['x' => 2], ['x' => 1]];

        $this->assertFalse(AttributeEngine::sameValue($list1, $list2));
    }

    public function test_same_value_detects_a_real_difference(): void
    {
        $this->assertFalse(AttributeEngine::sameValue(['value' => 'A'], ['value' => 'B']));
    }

    public function test_widget_kind_inference(): void
    {
        $this->assertSame('checkbox', AttributeEngine::widgetKind(true));
        $this->assertSame('number', AttributeEngine::widgetKind(42));
        $this->assertSame('number', AttributeEngine::widgetKind('42'));
        $this->assertSame('textarea', AttributeEngine::widgetKind(str_repeat('a', 81)));
        $this->assertSame('textarea', AttributeEngine::widgetKind("line one\nline two"));
        $this->assertSame('text', AttributeEngine::widgetKind('short text'));
    }

    public function test_humanize_leaf_path_hides_language_tag_and_drops_trailing_value(): void
    {
        $this->assertNull(AttributeEngine::humanizeLeafPath(['value', 'language_tag']));
        $this->assertSame('Title Differentiation', AttributeEngine::humanizeLeafPath(['title_differentiation', 'value']));
        $this->assertSame('Bullet Point', AttributeEngine::humanizeLeafPath(['bullet_point']));
    }

    public function test_schema_field_kind_detects_enum_via_any_of(): void
    {
        $this->assertSame('enum', AttributeEngine::schemaFieldKind(['enum' => ['a', 'b']]));
        $this->assertSame('enum', AttributeEngine::schemaFieldKind(['anyOf' => [['enum' => ['a']]]]));
        $this->assertSame('number', AttributeEngine::schemaFieldKind(['type' => 'number']));
        $this->assertSame('text', AttributeEngine::schemaFieldKind(['type' => 'string']));
    }

    public function test_schema_leaf_at_resolves_a_flat_field_on_a_single_level_item(): void
    {
        // e.g. item_package_weight: [{value: 5, unit: "KG"}]
        $itemSchema = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'number'],
                'unit' => ['type' => 'string', 'enum' => ['GR', 'KG', 'LB']],
            ],
        ];

        $leaf = AttributeEngine::schemaLeafAt($itemSchema, ['unit']);

        $this->assertSame('enum', AttributeEngine::schemaFieldKind($leaf));
    }

    public function test_schema_leaf_at_descends_through_a_nested_array_field(): void
    {
        // e.g. nutritional_info: [{energy: [{content: 100, unit: "kilocalories"}]}]
        $itemSchema = [
            'type' => 'object',
            'properties' => [
                'energy' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'number'],
                            'unit' => ['enum' => ['kilocalories', 'kilojoules']],
                        ],
                    ],
                ],
            ],
        ];

        $leaf = AttributeEngine::schemaLeafAt($itemSchema, ['energy', 'unit']);

        $this->assertSame('enum', AttributeEngine::schemaFieldKind($leaf));
        $this->assertSame(['kilocalories', 'kilojoules'], array_keys(AttributeEngine::schemaFieldEnumOptions($leaf)));
    }

    public function test_schema_leaf_at_returns_null_for_a_path_the_schema_does_not_describe(): void
    {
        $this->assertNull(AttributeEngine::schemaLeafAt(['properties' => ['value' => ['type' => 'string']]], ['unit']));
        $this->assertNull(AttributeEngine::schemaLeafAt(null, ['value']));
    }

    public function test_dedupe_images_by_variant_keeps_one_entry_per_variant_regardless_of_resolution(): void
    {
        $images = [
            ['variant' => 'MAIN', 'link' => 'main-75.jpg'],
            ['variant' => 'MAIN', 'link' => 'main-500.jpg'],
            ['variant' => 'MAIN', 'link' => 'main-1920.jpg'],
            ['variant' => 'PT01', 'link' => 'pt01-75.jpg'],
            ['variant' => 'PT01', 'link' => 'pt01-500.jpg'],
        ];

        $deduped = AttributeEngine::dedupeImagesByVariant($images);

        $this->assertCount(2, $deduped);
        $this->assertSame('main-75.jpg', $deduped[0]['link']); // first entry seen per variant is kept
        $this->assertSame('pt01-75.jpg', $deduped[1]['link']);
    }

    public function test_dedupe_images_by_variant_keeps_entries_with_no_variant_field_untouched(): void
    {
        // No reliable identity to dedupe on without a variant key, so every
        // entry is kept — matches the legacy behavior exactly.
        $images = [['link' => 'a.jpg'], ['link' => 'a.jpg']];

        $this->assertCount(2, AttributeEngine::dedupeImagesByVariant($images));
    }

    public function test_summarize_listing_issues_includes_enforcement_actions_when_present(): void
    {
        $issues = [[
            'severity' => 'ERROR', 'message' => 'Missing required attribute',
            'enforcements' => ['actions' => [['action' => 'LISTING_SUPPRESSED']]],
        ]];

        $summary = AttributeEngine::summarizeListingIssues($issues);

        $this->assertSame('ERROR: Missing required attribute [LISTING_SUPPRESSED]', $summary);
    }

    public function test_summarize_listing_issues_omits_the_brackets_when_no_enforcement_is_attached(): void
    {
        $issues = [['severity' => 'INFO', 'message' => 'Just FYI']];

        $this->assertSame('INFO: Just FYI', AttributeEngine::summarizeListingIssues($issues));
    }

    public function test_summarize_listing_issues_joins_multiple_issues_with_newlines(): void
    {
        $issues = [
            ['severity' => 'ERROR', 'message' => 'First'],
            ['severity' => 'WARNING', 'message' => 'Second'],
        ];

        $this->assertSame("ERROR: First\nWARNING: Second", AttributeEngine::summarizeListingIssues($issues));
    }
}
