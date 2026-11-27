<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per ranked account in a daily-captured Social Insights "top followed" leaderboard. Rows are immutable once written. */
class TeamAppSocialFollowSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'captured_at', 'period', 'rank', 'account_type', 'account_id', 'display_name', 'username',
        'sport_type', 'email', 'phone', 'new_followers', 'total_followers',
    ];

    // captured_at is deliberately NOT cast to 'date' — see
    // TeamAppSocialPostSnapshot's own note (same fix as
    // WalmartUsListingAudit::as_of_date). Kept a plain string throughout.
}
