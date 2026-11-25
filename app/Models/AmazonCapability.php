<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmazonCapability extends Model
{
    public const USABLE_STATUSES = ['pass', 'partial'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fields_requested' => 'array',
            'fields_rejected' => 'array',
            'field_stats' => 'array',
            'notes' => 'array',
            'probed_at' => 'datetime',
        ];
    }

    /** True when the last probe showed this capability returning data. */
    public static function isUsable(string $key): bool
    {
        return static::query()->where('key', $key)->whereIn('status', self::USABLE_STATUSES)->exists();
    }

    /** True when the last probe returned this field with at least one non-empty value. */
    public static function fieldReturned(string $key, string $fieldPath): bool
    {
        $row = static::query()->where('key', $key)->whereIn('status', self::USABLE_STATUSES)->first();
        $stats = $row?->field_stats[$fieldPath] ?? null;

        return $stats !== null && ($stats['non_empty'] ?? 0) > 0;
    }
}
