<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** subtopic_id null = snippet attached at topic level, not a specific subtopic. Never deduped across snapshots — rotation itself is a signal. */
class FeedbackSnippet extends Model
{
    protected $table = 'feedback_snippets';

    public $timestamps = false;

    protected $fillable = ['topic_id', 'subtopic_id', 'snippet'];
}
