<?php

namespace App\Amazon\Probe;

/**
 * One analytics_vendorAnalytics_2024_09_30 query, built from a selection
 * tree so that fields Amazon rejects can be removed and the query sent again.
 *
 * Selection trees: an int key holds a leaf field name; a string key holds a
 * nested selection, e.g. ['sourcing' => ['openPurchaseOrderQuantity',
 * 'netReceived' => ['units']]].
 *
 * Field names follow Amazon's published schema
 * (amzn/selling-partner-api-models, schemas/data-kiosk/
 * analytics_vendorAnalytics_2024_09_30.graphql, read 2026-11-25). From docs:
 * the live account can still reject a field the schema lists.
 */
final class DataKioskQuery
{
    /**
     * @param  'manufacturingView'|'sourcingView'  $view
     * @param  'DAY'|'WEEK'|'MONTH'  $aggregateBy
     * @param  list<string>  $groupBy
     * @param  array<int|string, mixed>  $metrics
     */
    public function __construct(
        public readonly string $view,
        public readonly string $aggregateBy,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly array $groupBy,
        public readonly array $metrics,
        public readonly ?string $currencyCode = 'CAD',
    ) {}

    public function toGraphQl(): string
    {
        $args = [
            "aggregateBy: {$this->aggregateBy}",
            "startDate: \"{$this->startDate}\"",
            "endDate: \"{$this->endDate}\"",
        ];
        if ($this->currencyCode !== null) {
            $args[] = "currencyCode: \"{$this->currencyCode}\"";
        }

        $groupBy = $this->groupBy === [] ? '' : 'groupByKey { '.implode(' ', $this->groupBy).' } ';

        return 'query { analytics_vendorAnalytics_2024_09_30 { '
            .$this->view.'('.implode(', ', $args).') { startDate endDate metrics { '
            .$groupBy.'metrics { '.self::render($this->metrics).' } } } } }';
    }

    /** @return list<string> dotted leaf paths, e.g. sourcing.netReceived.units */
    public function fields(): array
    {
        return self::leaves($this->metrics, '');
    }

    /** A copy without the named fields, wherever they appear. Empty branches are dropped. */
    public function without(array $names): self
    {
        return new self($this->view, $this->aggregateBy, $this->startDate, $this->endDate, $this->groupBy, self::prune($this->metrics, $names), $this->currencyCode);
    }

    public function isEmpty(): bool
    {
        return $this->metrics === [];
    }

    /**
     * Field names named in GraphQL "FieldUndefined" validation errors, e.g.
     * "Validation error of type FieldUndefined: Field 'procurableProductOOS'
     * in type 'ProductAvailability' is undefined" (verified live in the old
     * app, context.md 2026-08-27). GraphQL reports every invalid field in one
     * validation pass.
     *
     * @return list<string>
     */
    public static function undefinedFields(string $errorText): array
    {
        preg_match_all("/Field '([A-Za-z0-9_]+)' in type '[A-Za-z0-9_]+' is undefined/", $errorText, $m);

        return array_values(array_unique($m[1]));
    }

    private static function render(array $selection): string
    {
        $parts = [];
        foreach ($selection as $key => $value) {
            $parts[] = is_int($key) ? $value : $key.' { '.self::render($value).' }';
        }

        return implode(' ', $parts);
    }

    private static function leaves(array $selection, string $prefix): array
    {
        $out = [];
        foreach ($selection as $key => $value) {
            if (is_int($key)) {
                $out[] = $prefix.$value;
            } else {
                array_push($out, ...self::leaves($value, $prefix.$key.'.'));
            }
        }

        return $out;
    }

    private static function prune(array $selection, array $names): array
    {
        $out = [];
        foreach ($selection as $key => $value) {
            if (is_int($key)) {
                if (! in_array($value, $names, true)) {
                    $out[] = $value;
                }

                continue;
            }
            if (in_array($key, $names, true)) {
                continue;
            }
            $child = self::prune($value, $names);
            if ($child !== []) {
                $out[$key] = $child;
            }
        }

        return $out;
    }
}
