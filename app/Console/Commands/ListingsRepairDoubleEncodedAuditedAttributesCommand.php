<?php

namespace App\Console\Commands;

use App\Models\ListingAudit;
use App\Models\ScListingAudit;
use Illuminate\Console\Command;

/**
 * Repairs listing_audit / sc_listing_audit rows whose audited_attributes
 * column is double-encoded: a JSON string handed to an array-cast column
 * gets json_encode()'d again by Eloquent's setter rather than stored as-is,
 * leaving a JSON string *of* a JSON string. Hit twice from two different
 * causes — first in the one-time legacy-data import command (fixed by
 * decoding before create()), then in ListingAuditService::auditOne()'s own
 * live "Audit Listings" path (fixed by not pre-encoding before assignment)
 * — this command repairs whatever double-encoded rows either cause left
 * behind, on both channels. Detects exactly that shape (decodes to a
 * string, not an array) and re-saves the properly decoded array. Safe to
 * re-run — rows that are already correct are left untouched.
 */
class ListingsRepairDoubleEncodedAuditedAttributesCommand extends Command
{
    protected $signature = 'listings:repair-double-encoded-audited-attributes';

    protected $description = 'Repair double-encoded audited_attributes JSON on listing_audit and sc_listing_audit rows';

    public function handle(): int
    {
        foreach ([ListingAudit::class, ScListingAudit::class] as $model) {
            $fixed = 0;
            $checked = 0;

            $model::query()->whereNotNull('audited_attributes')->select(['id', 'audited_attributes'])
                ->chunkById(200, function ($rows) use (&$fixed, &$checked) {
                    foreach ($rows as $row) {
                        $checked++;
                        $decodedOnce = $row->getRawOriginal('audited_attributes');
                        $decodedOnce = is_string($decodedOnce) ? json_decode($decodedOnce, true) : $decodedOnce;

                        if (! is_string($decodedOnce)) {
                            continue;
                        }

                        $decodedTwice = json_decode($decodedOnce, true);
                        if (! is_array($decodedTwice)) {
                            continue;
                        }

                        $row->audited_attributes = $decodedTwice;
                        $row->save();
                        $fixed++;
                    }
                });

            $this->info("{$model}: checked {$checked} row(s), repaired {$fixed} double-encoded row(s).");
        }

        return self::SUCCESS;
    }
}
