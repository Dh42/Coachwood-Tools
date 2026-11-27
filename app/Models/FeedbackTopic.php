<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Never overwritten — a new snapshot is always new rows, so this reads as a real time series. */
class FeedbackTopic extends Model
{
    protected $table = 'feedback_topics';

    public $timestamps = false;

    protected $fillable = [
        'raw_snapshot_id', 'snapshot_date', 'asin', 'polarity', 'topic',
        'number_of_mentions', 'occurrence_percentage', 'star_rating_impact',
        'browse_node_occurrence_pct', 'browse_node_star_impact',
        'parent_asin_mentions', 'parent_asin_occurrence_pct', 'parent_asin_star_impact',
        'child_most_mentions_asin', 'child_most_mentions_count',
        'child_least_mentions_asin', 'child_least_mentions_count',
    ];

    protected function casts(): array
    {
        return ['snapshot_date' => 'date'];
    }

    public function subtopics(): HasMany
    {
        return $this->hasMany(FeedbackSubtopic::class, 'topic_id');
    }

    public function snippets(): HasMany
    {
        return $this->hasMany(FeedbackSnippet::class, 'topic_id')->whereNull('subtopic_id');
    }
}
