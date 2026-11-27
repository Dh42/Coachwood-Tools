<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Keyed on `date` (no incrementing id) — one row per calendar day, upserted.
 */
class TeamAppAdSnapshot extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'date';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'date', 'impressions', 'clicks', 'ad_requests', 'viewable_impressions', 'measurable_impressions',
    ];

    // Without this, a plain 'date' cast still serializes for storage using
    // the model's default datetime format ('Y-m-d H:i:s') — harmless on
    // MySQL (a genuine DATE column truncates it either way), but on SQLite
    // (this app's test suite) it stores "2026-09-03 00:00:00" instead of
    // "2026-09-03", which then sorts *after* a plain "2026-09-03" upper
    // bound in a whereBetween(), silently excluding today's own row.
    protected $dateFormat = 'Y-m-d';

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
