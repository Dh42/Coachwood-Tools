<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per ranked post/clip in a daily-captured Social Insights leaderboard. Rows are immutable once written. */
class TeamAppSocialPostSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'captured_at', 'period', 'rank', 'post_id', 'post_type', 'visibility', 'visibility_team_id',
        'post_created_at', 'text', 'author_user_id', 'author_name', 'author_username', 'author_email',
        'team_id', 'team_name', 'published_in_period', 'likes_in_period', 'dislikes_in_period',
        'shares_in_period', 'other_reactions_in_period', 'reactions_in_period', 'comments_in_period',
        'interactions_in_period', 'last_interaction_at', 'viewers_in_period', 'total_reactions',
        'total_comments', 'total_view_count',
    ];

    // captured_at is deliberately NOT cast to 'date' — same fix as
    // WalmartUsListingAudit::as_of_date. A 'date' cast here would share
    // this model's single $dateFormat with post_created_at/
    // last_interaction_at (both real datetimes), and Eloquent's default
    // dateFormat ('Y-m-d H:i:s') would then serialize captured_at with a
    // time component on any Eloquent-path write (e.g. ::create(), not just
    // the command's own raw DB::table()->insert()) — on SQLite (this app's
    // test suite) that silently breaks a plain '->where('captured_at',
    // '2026-09-08')' equality match against a caller's plain date string.
    // Kept a plain string throughout; callers already only ever compare it
    // against/format it as 'Y-m-d'.
    protected function casts(): array
    {
        return [
            'post_created_at' => 'datetime',
            'published_in_period' => 'boolean',
            'last_interaction_at' => 'datetime',
        ];
    }
}
