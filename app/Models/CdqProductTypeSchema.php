<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdqProductTypeSchema extends Model
{
    // The brief's schema names every cdq_* table singular — Eloquent would
    // otherwise infer the pluralized "cdq_product_type_schemas".
    protected $table = 'cdq_product_type_schema';

    protected $fillable = [
        'product_type', 'marketplace_id', 'schema_version', 'required_json', 'recommended_json', 'raw_schema', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'required_json' => 'array',
            'recommended_json' => 'array',
            'raw_schema' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
