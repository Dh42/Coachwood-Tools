<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CdqScore extends Model
{
    protected $table = 'cdq_score';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'check_config_version_id', 'attr_score', 'text_score', 'image_score',
        'aplus_score', 'consist_score', 'total_score', 'grade', 'scored_at', 'consist_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'consist_checked_at' => 'datetime',
            'attr_score' => 'decimal:2',
            'text_score' => 'decimal:2',
            'image_score' => 'decimal:2',
            'aplus_score' => 'decimal:2',
            'consist_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'scored_at' => 'datetime',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CdqListingSnapshot::class, 'snapshot_id');
    }

    public function checkConfigVersion(): BelongsTo
    {
        return $this->belongsTo(CdqCheckConfigVersion::class, 'check_config_version_id');
    }

    public function defects(): HasMany
    {
        return $this->hasMany(CdqDefect::class, 'snapshot_id', 'snapshot_id');
    }
}
