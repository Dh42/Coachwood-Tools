<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalmartUsListingAuditIssue extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'audit_id', 'source', 'code', 'severity', 'field', 'message', 'recommended_action',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(WalmartUsListingAudit::class, 'audit_id');
    }
}
