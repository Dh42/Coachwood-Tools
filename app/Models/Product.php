<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The Vendor Central (.ca) product list. Read-only in Phase 0: copied from
 * the old app by `legacy:import-products`. Ported from the old app; its
 * relations to Price Monitor and image audits come back with those tools.
 */
#[Fillable(['asin', 'sku', 'title', 'type', 'list_price', 'cost', 'cogs', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'list_price' => 'decimal:2',
            'cost' => 'decimal:2',
            'cogs' => 'decimal:2',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function listingEdits(): HasMany
    {
        return $this->hasMany(ListingEdit::class);
    }

    public function listingAudits(): HasMany
    {
        return $this->hasMany(ListingAudit::class);
    }

    public function latestListingAudit(): HasOne
    {
        return $this->hasOne(ListingAudit::class)->latestOfMany('checked_at');
    }
}
