<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalmartUsCatalogScore extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_at', 'listing_quality', 'content_score', 'offer_score', 'rating_review_score',
        'price_score', 'shipping_score', 'transactibility_score',
        'item_defect_cnt', 'defect_ratio', 'item_count',
    ];

    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
            'listing_quality' => 'decimal:2',
            'content_score' => 'decimal:2',
            'offer_score' => 'decimal:2',
            'rating_review_score' => 'decimal:2',
            'price_score' => 'decimal:2',
            'shipping_score' => 'decimal:2',
            'transactibility_score' => 'decimal:2',
            'defect_ratio' => 'decimal:4',
        ];
    }
}
