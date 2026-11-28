<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingAuditAttributeCatalog extends Model
{
    protected $table = 'listing_audit_attribute_catalog';

    protected $primaryKey = 'attribute_key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['attribute_key', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
