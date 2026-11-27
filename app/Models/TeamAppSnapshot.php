<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamAppSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['captured_at', 'users', 'teams'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(TeamAppCategorySnapshot::class, 'snapshot_id');
    }
}
