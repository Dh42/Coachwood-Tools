<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScListingEdit extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id', 'user_id', 'asin', 'sku', 'attribute', 'old_value', 'new_value', 'submission_id', 'status', 'issues_json', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ScProduct::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
