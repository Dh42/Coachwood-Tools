<?php

namespace App\Legacy;

/**
 * Shared by the compare:* commands: renders rows the same way on MariaDB
 * and SQLite, then hashes them. Only counts and 12-character hashes are
 * ever printed.
 */
final class RowHasher
{
    /**
     * Every number to 4 decimal places and every date-time to the second.
     * An md5 (a hashed blob) is left as it is.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalise(array $row): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return self::normalise($value);
            }
            if ($value === null) {
                return null;
            }
            if (is_numeric($value) && ! preg_match('/^[0-9a-f]{32}$/', (string) $value)) {
                return number_format((float) $value, 4, '.', '');
            }

            return substr((string) $value, 0, 19);
        }, $row);
    }

    /**
     * Rows in byte order of their values, the same whatever database they
     * came from. For tables compared without IDs.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function sorted(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => strcmp(json_encode(array_values($a)), json_encode(array_values($b))));

        return $rows;
    }

    /** @param  array<mixed>  $rows */
    public static function hash(array $rows): string
    {
        return substr(hash('sha256', json_encode($rows)), 0, 12);
    }
}
