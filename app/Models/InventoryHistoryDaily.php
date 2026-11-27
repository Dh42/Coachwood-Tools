<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_title', 'day', 'ending_inventory_units', 'ingested_at'])]
class InventoryHistoryDaily extends Model
{
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'ending_inventory_units' => 'integer',
            'ingested_at' => 'datetime',
        ];
    }
}
