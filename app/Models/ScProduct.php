<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The Seller Central (US) product list, copied from the old app daily by
 * `legacy:import-products`. The SC Listings Editor's Sync Products also
 * writes it from Amazon's listings report; the next import puts the old
 * app's copy back.
 */
class ScProduct extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['asin', 'sku', 'title', 'status', 'synced_at'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Active');
    }

    public function listingEdits(): HasMany
    {
        return $this->hasMany(ScListingEdit::class, 'product_id');
    }

    public function listingAudits(): HasMany
    {
        return $this->hasMany(ScListingAudit::class, 'product_id');
    }

    public function latestListingAudit(): HasOne
    {
        return $this->hasOne(ScListingAudit::class, 'product_id')->latestOfMany('checked_at');
    }
}
