<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per (asin, polarity, topic, month) — only occurrence % is available at this grain, no mention counts (confirmed from Amazon's own schema). */
class FeedbackTopicTrend extends Model
{
    protected $table = 'feedback_topic_trends';

    public $timestamps = false;

    protected $fillable = [
        'raw_trend_snapshot_id', 'snapshot_date', 'asin', 'polarity', 'topic',
        'month_start', 'month_end', 'asin_occurrence_pct', 'parent_asin_occurrence_pct', 'browse_node_occurrence_pct',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'month_start' => 'date',
            'month_end' => 'date',
        ];
    }
}
