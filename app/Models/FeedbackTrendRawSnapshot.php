<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only — the separate 6-month-trend collector's own raw snapshot row, one API call per ASIN distinct from FeedbackRawSnapshot. */
class FeedbackTrendRawSnapshot extends Model
{
    protected $table = 'feedback_trend_raw_snapshots';

    public $timestamps = false;

    protected $fillable = ['snapshot_date', 'asin', 'marketplace_id', 'source_catalog', 'http_status', 'raw_response_gz', 'pulled_at'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'pulled_at' => 'datetime',
        ];
    }
}
