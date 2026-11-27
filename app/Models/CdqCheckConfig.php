<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CdqCheckConfig extends Model
{
    protected $table = 'cdq_check_config';

    public $timestamps = false;

    protected $fillable = [
        'version_id', 'check_code', 'dimension', 'points', 'per_instance', 'instance_cap', 'enabled', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'per_instance' => 'boolean',
            'instance_cap' => 'decimal:2',
            'enabled' => 'boolean',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CdqCheckConfigVersion::class, 'version_id');
    }
}
