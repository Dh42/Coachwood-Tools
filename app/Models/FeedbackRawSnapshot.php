<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Append-only — one row per pull attempt per ASIN, whatever the HTTP outcome. "Current state" reads always take the latest row per ASIN by pulled_at. */
class FeedbackRawSnapshot extends Model
{
    protected $table = 'feedback_raw_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_date', 'asin', 'marketplace_id', 'source_catalog', 'http_status',
        'country_code', 'data_start_date', 'data_end_date', 'raw_response_gz', 'pulled_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'data_start_date' => 'date',
            'data_end_date' => 'date',
            'pulled_at' => 'datetime',
        ];
    }

    public function topics(): HasMany
    {
        return $this->hasMany(FeedbackTopic::class, 'raw_snapshot_id');
    }
}
