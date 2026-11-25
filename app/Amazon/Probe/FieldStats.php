<?php

namespace App\Amazon\Probe;

/**
 * Describes the fields in a set of response rows without exposing their
 * contents (decision 2026-11-24: no ASINs, no amounts leave the server).
 *
 * For every field path it reports type, how often it is empty, and for
 * numbers how often it is zero or negative. Only these are given as values:
 *  - min and max of ratio-like numbers (rates, ROOS, PPM, shares, lead
 *    times), which answer scale questions such as "is sourceableROOS 0-1
 *    or 0-100?"
 *  - min and max of dates
 *  - counts per value for a short list of non-confidential codes
 *    (currency, vendor code, marketplace)
 *  - true and false counts for booleans
 * Every other string is reduced to a count of distinct values.
 */
final class FieldStats
{
    private const LISTED_LEAVES = ['currencyCode', 'vendorCode', 'marketplaceId', 'MarketplaceId', 'countryCode', 'ItemCondition', 'processingStatus'];

    private const LISTED_PATH_SUFFIXES = ['sellingParty.partyId', 'marketplace.id'];

    private const RANGE_PATTERN = '/(rate|roos|ppm|share|percent|ultrafasttrack|leadtime)/i';

    private const DATE_LEAF_PATTERN = '/(date|datetime|time)$/i';

    private const DISTINCT_CAP = 10000;

    /** @var array<string, array<string, mixed>> */
    private array $acc = [];

    private int $rows = 0;

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    public static function describe(iterable $rows): array
    {
        $stats = new self;
        foreach ($rows as $row) {
            $stats->add($row);
        }

        return $stats->result();
    }

    /** @param  array<string, mixed>  $row */
    public function add(array $row): void
    {
        $this->rows++;
        $this->walk($row, '', false);
    }

    public function rowCount(): int
    {
        return $this->rows;
    }

    /** @return array<string, array<string, mixed>> */
    public function result(): array
    {
        $out = [];
        ksort($this->acc);

        foreach ($this->acc as $path => $a) {
            $denominator = $a['in_list'] ? $a['seen'] : $this->rows;
            $entry = [
                'type' => implode('|', array_keys($a['types'])),
                'non_empty' => $a['non_empty'],
                'empty_rate' => $denominator > 0 ? round(1 - $a['non_empty'] / $denominator, 4) : null,
            ];

            if ($a['numbers'] > 0) {
                $entry['zero_rate'] = round($a['zero'] / $a['numbers'], 4);
                $entry['has_negative'] = $a['negative'] > 0;
                if ($a['min'] !== null) {
                    $entry['range'] = [$a['min'], $a['max']];
                }
            }
            if ($a['date_min'] !== null) {
                $entry['date_range'] = [$a['date_min'], $a['date_max']];
            }
            if ($a['values'] !== []) {
                ksort($a['values']);
                $entry['values'] = $a['values'];
            } elseif ($a['distinct'] !== []) {
                $entry['distinct'] = count($a['distinct']).(count($a['distinct']) >= self::DISTINCT_CAP ? '+' : '');
            }
            if ($a['bools'] !== ['true' => 0, 'false' => 0]) {
                $entry['bools'] = $a['bools'];
            }
            if ($a['len_min'] !== null) {
                $entry['list_length'] = [$a['len_min'], $a['len_max']];
            }

            $out[$path] = $entry;
        }

        return $out;
    }

    private function walk(array $node, string $prefix, bool $inList): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $this->walk($value, $path, $inList);

                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                $this->record($path, $value, $inList);
                foreach ($value as $item) {
                    if (is_array($item) && ! array_is_list($item)) {
                        $this->walk($item, $path.'[]', true);
                    }
                }

                continue;
            }

            $this->record($path, $value, $inList);
        }
    }

    private function record(string $path, mixed $value, bool $inList): void
    {
        $a = &$this->acc[$path];
        $a ??= [
            'in_list' => $inList, 'seen' => 0, 'non_empty' => 0, 'types' => [],
            'numbers' => 0, 'zero' => 0, 'negative' => 0, 'min' => null, 'max' => null,
            'date_min' => null, 'date_max' => null, 'values' => [], 'distinct' => [],
            'bools' => ['true' => 0, 'false' => 0], 'len_min' => null, 'len_max' => null,
        ];
        $a['seen']++;

        $leaf = str_contains($path, '.') ? substr($path, strrpos($path, '.') + 1) : $path;
        $leaf = str_replace('[]', '', $leaf);

        if ($value === null || $value === '' || $value === []) {
            $a['types']['null'] = true;

            return;
        }

        $a['non_empty']++;

        if (is_array($value)) {
            $a['types']['list'] = true;
            $len = count($value);
            $a['len_min'] = $a['len_min'] === null ? $len : min($a['len_min'], $len);
            $a['len_max'] = $a['len_max'] === null ? $len : max($a['len_max'], $len);

            return;
        }

        if (is_bool($value)) {
            $a['types']['bool'] = true;
            $a['bools'][$value ? 'true' : 'false']++;

            return;
        }

        if (is_int($value) || is_float($value)) {
            $a['types'][is_int($value) ? 'int' : 'float'] = true;
            $a['numbers']++;
            if ($value == 0) {
                $a['zero']++;
            }
            if ($value < 0) {
                $a['negative']++;
            }
            if (preg_match(self::RANGE_PATTERN, $leaf)) {
                $a['min'] = $a['min'] === null ? $value : min($a['min'], $value);
                $a['max'] = $a['max'] === null ? $value : max($a['max'], $value);
            }

            return;
        }

        $a['types']['string'] = true;
        $string = (string) $value;

        if ($this->isListed($path, $leaf)) {
            $a['values'][$string] = ($a['values'][$string] ?? 0) + 1;

            return;
        }

        if (preg_match(self::DATE_LEAF_PATTERN, $leaf) && preg_match('/^\d{4}-\d{2}-\d{2}/', $string)) {
            $date = substr($string, 0, 10);
            $a['date_min'] = $a['date_min'] === null || $date < $a['date_min'] ? $date : $a['date_min'];
            $a['date_max'] = $a['date_max'] === null || $date > $a['date_max'] ? $date : $a['date_max'];

            return;
        }

        if (count($a['distinct']) < self::DISTINCT_CAP) {
            $a['distinct'][hash('xxh3', $string)] = true;
        }
    }

    private function isListed(string $path, string $leaf): bool
    {
        if (in_array($leaf, self::LISTED_LEAVES, true)) {
            return true;
        }
        $plain = str_replace('[]', '', $path);
        foreach (self::LISTED_PATH_SUFFIXES as $suffix) {
            if (str_ends_with($plain, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
