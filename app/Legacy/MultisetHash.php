<?php

namespace App\Legacy;

/**
 * An order-independent hash of a set of rows, for tables too large to sort
 * in PHP and whose IDs differ between the two apps (the ASP Tracker's
 * discounts and rollups). Each row is normalised (RowHasher) and hashed, and
 * the row hashes are added up modulo 2^60, so the result is the same in any
 * read order. Prints as 12 hex characters, like RowHasher::hash.
 */
final class MultisetHash
{
    private int $sum = 0;

    private int $count = 0;

    /** @param  array<string, mixed>  $row */
    public function add(array $row): void
    {
        $this->sum = ($this->sum + hexdec(substr(sha1(json_encode(RowHasher::normalise($row))), 0, 15))) & 0x0FFFFFFFFFFFFFFF;
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function digest(): string
    {
        return substr(str_pad(dechex($this->sum), 15, '0', STR_PAD_LEFT), 0, 12);
    }
}
