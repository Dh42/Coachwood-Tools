<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['inventory_item_id', 'variant_id', 'location_id', 'state', 'quantity', 'captured_at', 'source'])]
class InventorySnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'captured_at' => 'datetime',
        ];
    }
}
