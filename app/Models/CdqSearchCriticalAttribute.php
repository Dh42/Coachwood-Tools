<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdqSearchCriticalAttribute extends Model
{
    protected $table = 'cdq_search_critical_attribute';

    public $timestamps = false;

    protected $fillable = ['marketplace_id', 'product_type', 'attribute_name', 'notes'];
}
