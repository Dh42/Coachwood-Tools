<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The Seller Central (US) product list. Read-only in Phase 0: copied from
 * the old app by `legacy:import-products`. Ported from the old app; its
 * relations to the SC listings editor come back with that tool (step 11).
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
}
