<?php

namespace App\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * Shared by the compare:* commands: renders rows the same way on MariaDB
 * and SQLite, then hashes them. Only counts and 12-character hashes are
 * ever printed.
 */
final class RowHasher
{
    /**
     * Every number to 4 decimal places and every date-time to the second.
     * An md5 (a hashed blob) and any other text are left as they are. (Until
     * 2026-12-06 every text value was cut to 19 characters, so a difference
     * after that went unseen.)
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

            $value = (string) $value;

            return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value) ? substr($value, 0, 19) : $value;
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

    /**
     * Every row of a table, a chunk at a time, so a large table is never held
     * in memory whole. Rows in the order of the first column (the ID or key),
     * each normalised after $transform. Not the same value as hash() for the
     * same rows; compare it only with another hashTable().
     *
     * @param  list<string>  $columns  the ordering column first
     * @param  ?callable(array<string, mixed>): array<string, mixed>  $transform
     * @return array{int, string} row count and hash
     */
    public static function hashTable(?string $connection, string $table, array $columns, ?callable $transform = null, int $chunk = 2000): array
    {
        $context = hash_init('sha256');
        $count = 0;
        DB::connection($connection)->table($table)->select($columns)->orderBy($columns[0])
            ->chunk($chunk, function ($rows) use ($context, $transform, &$count) {
                foreach ($rows as $row) {
                    $row = (array) $row;
                    hash_update($context, json_encode(self::normalise($transform ? $transform($row) : $row))."\n");
                    $count++;
                }
            });

        return [$count, substr(hash_final($context), 0, 12)];
    }

    /** @param  array<mixed>  $rows */
    public static function hash(array $rows): string
    {
        return substr(hash('sha256', json_encode($rows)), 0, 12);
    }
}
