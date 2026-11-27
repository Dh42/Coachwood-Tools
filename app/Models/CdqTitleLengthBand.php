<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdqTitleLengthBand extends Model
{
    protected $table = 'cdq_title_length_band';

    public $timestamps = false;

    protected $fillable = ['marketplace_id', 'product_type', 'min_length', 'max_length', 'notes'];
}
