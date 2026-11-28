<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingAudit extends Model
{
    protected $table = 'listing_audit';

    public $timestamps = false;

    protected $fillable = [
        'product_id', 'has_serving_quantity', 'has_energy_content', 'has_total_fat', 'has_total_carbohydrate',
        'has_protein', 'bullet_point_count', 'has_regulatory_compliance', 'brand_code', 'manufacturer_code',
        'replenishment_category', 'aplus_status', 'image_count', 'contribution_mismatch_count',
        'contribution_mismatch_attrs', 'audited_attributes', 'listing_issues', 'listing_restrictions',
        'fetch_error', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'has_serving_quantity' => 'boolean',
            'has_energy_content' => 'boolean',
            'has_total_fat' => 'boolean',
            'has_total_carbohydrate' => 'boolean',
            'has_protein' => 'boolean',
            'has_regulatory_compliance' => 'boolean',
            'audited_attributes' => 'array',
            'listing_issues' => 'array',
            'listing_restrictions' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
