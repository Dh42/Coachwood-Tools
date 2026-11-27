<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AspIngestLog extends Model
{
    protected $table = 'asp_ingest_log';

    protected $fillable = [
        'store', 'kind', 'status', 'bulk_operation_id', 'since', 'started_at', 'finished_at',
        'orders_touched', 'lines_changed', 'unattributed_refunds', 'errors', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'since' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
