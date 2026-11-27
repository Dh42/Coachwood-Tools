<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalmartUsListingAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sku', 'as_of_date', 'run_at', 'snapshot_id',
        'listing_quality', 'content_score', 'offer_score', 'rating_review_score',
        'price_score', 'shipping_score', 'transactibility_score',
        'item_defect_cnt', 'defect_ratio', 'our_rule_score',
    ];

    protected function casts(): array
    {
        return [
            // Deliberately NOT cast as 'date' — Eloquent's default 'date'
            // cast serializes with the model's full datetime format even
            // for a DATE column (same bug already hit and fixed on
            // TeamAppAdSnapshot). Always assigned a plain 'Y-m-d' string
            // (Carbon::toDateString()) by WalmartUsListingAuditService.
            'run_at' => 'datetime',
            'listing_quality' => 'decimal:2',
            'content_score' => 'decimal:2',
            'offer_score' => 'decimal:2',
            'rating_review_score' => 'decimal:2',
            'price_score' => 'decimal:2',
            'shipping_score' => 'decimal:2',
            'transactibility_score' => 'decimal:2',
            'defect_ratio' => 'decimal:4',
            'our_rule_score' => 'decimal:2',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(WalmartUsListingSnapshot::class, 'snapshot_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(WalmartUsListingAuditIssue::class, 'audit_id');
    }
}
