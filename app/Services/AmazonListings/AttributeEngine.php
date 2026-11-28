<?php

namespace App\Services\AmazonListings;

/**
 * Generic JSON-attribute-tree utilities shared by both the Vendor Central
 * and Seller Central listing editors — ported verbatim from the legacy
 * tool's attributeLeafPaths()/getLeafPath()/setLeafPath()/canonicalize()/
 * sameAttributeValue(), confirmed byte-for-byte identical between the two
 * legacy files. Pure and stateless — knows nothing about which channel is
 * calling it.
 */
class AttributeEngine
{
    /**
     * Recurses into every nested array, skipping marketplace_id entirely.
     *
     * @return array<int, array{0: array<string>, 1: mixed}> [path segments, scalar value]
     */
    public static function leafPaths(array $item, array $prefix = []): array
    {
        $out = [];
        foreach ($item as $key => $value) {
            if ($key === 'marketplace_id') {
                continue;
            }
            $path = [...$prefix, $key];
            if (is_array($value)) {
                $out = [...$out, ...self::leafPaths($value, $path)];
            } else {
                $out[] = [$path, $value];
            }
        }

        return $out;
    }

    public static function getPath(array $item, array $path): mixed
    {
        $ref = $item;
        foreach ($path as $key) {
            if (! is_array($ref) || ! array_key_exists($key, $ref)) {
                return null;
            }
            $ref = $ref[$key];
        }

        return $ref;
    }

    /** Auto-vivifies intermediate array levels by reference. */
    public static function setPath(array &$item, array $path, mixed $value): void
    {
        $ref = &$item;
        foreach ($path as $i => $key) {
            if ($i === count($path) - 1) {
                $ref[$key] = $value;
            } else {
                if (! isset($ref[$key]) || ! is_array($ref[$key])) {
                    $ref[$key] = [];
                }
                $ref = &$ref[$key];
            }
        }
    }

    /**
     * Recursively ksorts associative (non-list) sub-arrays only — list
     * arrays keep their order, since bullet-point/entry position is
     * meaningful — then the caller compares via json_encode string equality.
     */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    public static function sameValue(mixed $a, mixed $b): bool
    {
        return json_encode(self::canonicalize($a)) === json_encode(self::canonicalize($b));
    }

    /**
     * snake_case -> Title Case, e.g. "bullet_point" -> "Bullet Point". Purely
     * cosmetic (a form-field label), not confirmed against the legacy
     * source verbatim since it wasn't part of the researched code — low
     * risk since it never affects submitted data, only a label string.
     */
    public static function humanizeKey(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Column headers/picker options read better without a grouping prefix
     * (e.g. "Nutrition: Calories" -> "Calories") once they're already
     * grouped visually in a table or dropdown.
     */
    public static function displayLabel(string $label): string
    {
        if (! str_contains($label, ':')) {
            return $label;
        }

        return trim(substr($label, strrpos($label, ':') + 1));
    }

    /**
     * Vendor Central's replenishmentCategory enum (NEW_PRODUCT,
     * PLANNED_REPLENISHMENT, REPLENISHABLE, NON_REPLENISHABLE, OBSOLETE,
     * ADHOC, ...) into a dense column-safe abbreviation — first letter of
     * each underscore-separated word, e.g. NEW_PRODUCT -> "NP",
     * PLANNED_REPLENISHMENT -> "PR", OBSOLETE -> "O". Generic over the
     * enum's actual members rather than a hardcoded lookup table, so a
     * value Amazon adds later still abbreviates sensibly.
     */
    public static function replenishmentAbbreviation(?string $category): ?string
    {
        if ($category === null || $category === '') {
            return null;
        }

        return strtoupper(implode('', array_map(fn ($word) => $word[0] ?? '', explode('_', $category))));
    }

    /**
     * One line per Listings Items issue — severity, message, and (when
     * Amazon attached one) the enforcement action(s) actually affecting
     * the listing's buyability, e.g. "ERROR: Missing required attribute
     * [LISTING_SUPPRESSED]". Meant for a plain HTML `title` tooltip (a
     * literal \n renders as a line break there), not markup — this app's
     * existing convention for hover detail (see the RC column), not a new
     * JS tooltip component for one field.
     */
    public static function summarizeListingIssues(array $issues): string
    {
        return implode("\n", array_map(function ($issue) {
            $severity = $issue['severity'] ?? '?';
            $message = $issue['message'] ?? '';
            $actions = array_filter(array_map(fn ($a) => $a['action'] ?? null, $issue['enforcements']['actions'] ?? []));

            return "{$severity}: {$message}".($actions ? ' ['.implode(', ', $actions).']' : '');
        }, $issues));
    }

    /**
     * language_tag is shown as read-only context elsewhere, not its own
     * editable field. A path ending in "value" drops that trailing segment
     * from the label (e.g. ['title_differentiation', 'value'] -> "Title
     * Differentiation", not "... Value").
     */
    public static function humanizeLeafPath(array $path): ?string
    {
        if (end($path) === 'language_tag') {
            return null;
        }
        $labels = array_map(self::humanizeKey(...), $path);
        if (end($labels) === 'Value') {
            array_pop($labels);
        }

        return implode(' ', $labels);
    }

    /**
     * Widget kind for the generic attribute loop, inferred purely from the
     * live value's PHP type/length — not from any JSON schema.
     */
    public static function widgetKind(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'checkbox',
            is_numeric($value) => 'number',
            is_string($value) && (strlen($value) > 80 || str_contains($value, "\n")) => 'textarea',
            default => 'text',
        };
    }

    /**
     * Walks a schema tree in parallel with leafPaths()'s value-tree walk, to
     * find the schema fragment for one leaf of one attribute's item shape —
     * e.g. path ['unit'] on item_package_weight's item schema resolves to
     * the {enum: [...]} fragment for its unit field, the same way
     * ['energy', 'unit'] resolves inside nutrition's schema. Generalizes
     * what the nutrition sub-form already did by hand, so the generic
     * attribute loop can offer the same enum dropdowns for any attribute
     * Amazon's schema constrains — not just nutrition.
     *
     * @param  ?array  $itemSchema  the schema for one item of the attribute (i.e. schema.properties[$attrKey].items), not the top-level array wrapper
     */
    public static function schemaLeafAt(?array $itemSchema, array $path): ?array
    {
        $node = $itemSchema;
        foreach ($path as $i => $segment) {
            if (! is_array($node)) {
                return null;
            }
            $node = $node['properties'][$segment] ?? null;
            if ($node === null) {
                return null;
            }
            $isLast = $i === count($path) - 1;
            if (! $isLast && ($node['type'] ?? null) === 'array' && isset($node['items'])) {
                $node = $node['items'];
            }
        }

        return $node;
    }

    /** Schema-driven widget kind — used for the nutrition sub-form and, via schemaLeafAt(), the generic attribute loop. */
    public static function schemaFieldKind(array $propSchema): string
    {
        if (isset($propSchema['enum'])) {
            return 'enum';
        }
        foreach ($propSchema['anyOf'] ?? [] as $branch) {
            if (isset($branch['enum'])) {
                return 'enum';
            }
        }
        if (($propSchema['type'] ?? '') === 'number') {
            return 'number';
        }

        return 'text';
    }

    /**
     * Reconstructed for the port, not confirmed byte-for-byte against the
     * legacy source (it wasn't quoted in the research pass) — Amazon's
     * product-type schemas expose enum options either as parallel
     * enum/enumNames arrays or as anyOf branches with const/title. Falls
     * back to the raw value as its own label if no name is given, so a
     * schema shape this doesn't anticipate degrades to an unlabeled but
     * still-functional option rather than breaking the field.
     *
     * @return array<string, string> value => label
     */
    public static function schemaFieldEnumOptions(array $propSchema): array
    {
        $options = [];

        if (isset($propSchema['enum'])) {
            foreach ($propSchema['enum'] as $i => $value) {
                $options[$value] = $propSchema['enumNames'][$i] ?? $value;
            }

            return $options;
        }

        foreach ($propSchema['anyOf'] ?? [] as $branch) {
            if (isset($branch['const'])) {
                $options[$branch['const']] = $branch['title'] ?? $branch['const'];
            } elseif (isset($branch['enum'])) {
                foreach ($branch['enum'] as $i => $value) {
                    $options[$value] = $branch['enumNames'][$i] ?? $value;
                }
            }
        }

        return $options;
    }

    /**
     * Ported verbatim from the legacy tool's dedupeImagesByVariant() —
     * Catalog Items returns every image variant (MAIN, PT01, PT02, ...) at
     * several resolutions each (e.g. a 75x75 thumbnail + 500x500 + 1920x1920
     * as separate array entries), so a raw count massively overcounts —
     * confirmed live, our port's naive array_unique(...,'link') count was
     * running ~3x the real image count since each resolution has its own
     * distinct URL. Keeping only the first entry seen per variant fixes it.
     *
     * @param  array<int, array{variant?: ?string}>  $images
     * @return array<int, array>
     */
    public static function dedupeImagesByVariant(array $images): array
    {
        $seen = [];
        $out = [];
        foreach ($images as $img) {
            $variant = $img['variant'] ?? null;
            if ($variant !== null) {
                if (isset($seen[$variant])) {
                    continue;
                }
                $seen[$variant] = true;
            }
            $out[] = $img;
        }

        return $out;
    }
}
