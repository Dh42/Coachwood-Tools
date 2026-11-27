<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CdqDefect extends Model
{
    protected $table = 'cdq_defect';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'dimension', 'check_code', 'severity', 'points', 'attribute_path', 'detail_json',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'detail_json' => 'array',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CdqListingSnapshot::class, 'snapshot_id');
    }
}
