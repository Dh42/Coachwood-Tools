<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composite-primary-keyed (snapshot_id, category) — no single incrementing
 * id, so this is only ever queried/inserted directly, never via find()/save().
 */
class TeamAppCategorySnapshot extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['snapshot_id', 'category', 'teams'];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(TeamAppSnapshot::class, 'snapshot_id');
    }
}
