<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackSubtopic extends Model
{
    protected $table = 'feedback_subtopics';

    public $timestamps = false;

    protected $fillable = ['topic_id', 'subtopic', 'number_of_mentions', 'occurrence_percentage'];

    public function snippets(): HasMany
    {
        return $this->hasMany(FeedbackSnippet::class, 'subtopic_id');
    }
}
