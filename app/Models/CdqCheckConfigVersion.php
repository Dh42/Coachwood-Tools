<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CdqCheckConfigVersion extends Model
{
    protected $table = 'cdq_check_config_version';

    public $timestamps = false;

    protected $fillable = ['effective_from', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
        ];
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(CdqCheckConfig::class, 'version_id');
    }
}
