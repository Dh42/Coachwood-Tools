<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdqListingSnapshot extends Model
{
    // The brief names every cdq_* table singular — Eloquent's default
    // pluralized guess ("cdq_listing_snapshots") would be wrong.
    protected $table = 'cdq_listing_snapshot';

    // captured_at is the real timestamp — no created_at/updated_at columns
    // on this table, matching the brief's own schema exactly.
    public $timestamps = false;

    protected $fillable = [
        'asin', 'sku', 'vendor_code', 'marketplace_id', 'product_type', 'parent_asin',
        'content_hash', 'catalog_json', 'attributes_json', 'main_image_hash', 'aplus_json', 'issues_json', 'issues_checked_at', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'catalog_json' => 'array',
            'attributes_json' => 'array',
            'aplus_json' => 'array',
            'issues_json' => 'array',
            'issues_checked_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }
}
