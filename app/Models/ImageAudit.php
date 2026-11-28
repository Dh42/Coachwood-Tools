<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id', 'unattributed_variants', 'unattributed_count',
        'not_live_variants', 'not_live_count', 'fetch_error', 'checked_at',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
