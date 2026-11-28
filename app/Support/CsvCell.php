<?php

namespace App\Support;

/**
 * CSV cells that hold text typed by people outside the company (team names,
 * emails). A spreadsheet treats a cell starting with =, +, -, @, a tab or a
 * carriage return as a formula, so such a cell gets a leading apostrophe and
 * opens as plain text (OWASP's advice for CSV injection).
 */
final class CsvCell
{
    public static function text(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
