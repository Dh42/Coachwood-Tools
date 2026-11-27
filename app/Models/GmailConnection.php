<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'refresh_token', 'connected_by'])]
class GmailConnection extends Model
{
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
