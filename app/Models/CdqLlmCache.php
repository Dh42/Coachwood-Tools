<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Composite-keyed (content_hash, model, prompt_version), no surrogate id —
 * Eloquent doesn't support a real composite primary key, so this is always
 * looked up/written via explicit where()/create() rather than find()/save()
 * or updateOrCreate(). A cache entry is effectively write-once: the same
 * (content_hash, model, prompt_version) always produces the same response,
 * so nothing ever needs to update an existing row in place.
 */
class CdqLlmCache extends Model
{
    protected $table = 'cdq_llm_cache';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'content_hash';

    protected $fillable = ['content_hash', 'model', 'prompt_version', 'response_json', 'created_at'];

    protected function casts(): array
    {
        return [
            'response_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
