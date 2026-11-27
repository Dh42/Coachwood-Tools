<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalmartUsItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sku', 'wpid', 'gtin', 'product_name', 'publish_status',
        'lifecycle_status', 'price', 'internal_sku', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'last_synced_at' => 'datetime',
        ];
    }
}
