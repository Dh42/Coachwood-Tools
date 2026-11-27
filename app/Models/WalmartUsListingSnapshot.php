<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalmartUsListingSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'walmart_us_listing_snapshot';

    protected $fillable = ['sku', 'wpid', 'content_hash', 'item_json', 'listing_quality_json', 'captured_at'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    public function audits(): HasMany
    {
        return $this->hasMany(WalmartUsListingAudit::class, 'snapshot_id');
    }
}
