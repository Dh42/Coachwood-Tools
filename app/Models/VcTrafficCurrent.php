<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Materialized "latest pulled_at wins" dedup of VcTrafficSnapshot — read-heavy queries (e.g. VC Birdseye) hit this, never the raw snapshots table. Rebuilt via VcTrafficService::refreshCurrentTable(). */
class VcTrafficCurrent extends Model
{
    protected $table = 'vc_traffic_current';

    public $timestamps = false;

    protected $fillable = [
        'dedup_key', 'asin', 'period_start', 'period_end', 'glance_views', 'lost_featured_offer_pct',
        'sourceable_roos_pct', 'sell_through_rate_pct', 'pulled_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pulled_at' => 'datetime',
        ];
    }
}
