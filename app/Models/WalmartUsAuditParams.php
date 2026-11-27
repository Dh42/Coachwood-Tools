<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalmartUsAuditParams extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'effective_from', 'min_title_length', 'max_title_length', 'min_image_count',
        'min_image_resolution_px', 'min_description_length', 'min_bullet_count',
        'price_guardrail_pct', 'require_shipping_template', 'exclude_trending_items',
        'exclude_wfs_items', 'reason', 'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'price_guardrail_pct' => 'decimal:4',
            'require_shipping_template' => 'boolean',
            'exclude_trending_items' => 'boolean',
            'exclude_wfs_items' => 'boolean',
        ];
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** The current version — latest row, same resolution as every other versioned-params table in this app. */
    public static function current(): ?self
    {
        return static::orderByDesc('effective_from')->orderByDesc('id')->first();
    }
}
