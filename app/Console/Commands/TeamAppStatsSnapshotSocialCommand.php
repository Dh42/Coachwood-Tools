<?php

namespace App\Console\Commands;

use App\Services\TeamAppStats\SocialInsightsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily standing job (scheduled near end-of-day, see the registration
 * migration): captures the current WEEK-period Social Insights
 * leaderboards so a past day's "top posts"/"top followed" can still be
 * viewed later — the dashboard's own live section always calls the API
 * fresh, this is purely for history. Only WEEK is captured (see the
 * create-tables migration's own docblock for why).
 *
 * Each report is captured independently — a failure fetching one never
 * blocks writing the other, same "an ancillary lookup never breaks a
 * working one" principle used throughout this app's other integrations.
 * Re-running the same day overwrites that day's rows (delete + insert,
 * not upsert-by-rank — a leaderboard can shrink between runs), so this is
 * safe to run more than once a day.
 */
class TeamAppStatsSnapshotSocialCommand extends Command
{
    protected $signature = 'team-app-stats:snapshot-social';

    protected $description = "Captures today's Social Insights top-posts/top-followed (WEEK period) leaderboards for history";

    private const PERIOD = 'WEEK';

    public function handle(SocialInsightsClient $client): int
    {
        // The date where the task runs (23:55 Toronto), not UTC, which is
        // already the next day by then (found issue 12).
        $today = now(config('team_app_stats.social_timezone'))->toDateString();
        $ok = true;

        $posts = $client->topPosts(self::PERIOD);
        if ($posts === null) {
            $this->error('Social Insights top-posts request failed — skipping.');
            $ok = false;
        } else {
            $this->storePosts($today, $posts);
            $this->info('OK top-posts: '.count($posts).' row(s) @ '.$today);
        }

        $followed = $client->topFollowed(self::PERIOD);
        if ($followed === null) {
            $this->error('Social Insights top-followed request failed — skipping.');
            $ok = false;
        } else {
            $this->storeFollowed($today, $followed);
            $this->info('OK top-followed: '.count($followed).' row(s) @ '.$today);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<array<string, mixed>>  $posts */
    private function storePosts(string $today, array $posts): void
    {
        DB::transaction(function () use ($today, $posts) {
            DB::table('team_app_social_post_snapshots')
                ->where('captured_at', $today)->where('period', self::PERIOD)->delete();

            if ($posts === []) {
                return;
            }

            $rows = array_map(fn (array $p) => [
                'captured_at' => $today,
                'period' => self::PERIOD,
                'rank' => $p['rank'] ?? 0,
                'post_id' => $p['postId'] ?? '',
                'post_type' => $p['postType'] ?? null,
                'visibility' => $p['visibility'] ?? null,
                'visibility_team_id' => $p['visibilityTeamId'] ?? null,
                'post_created_at' => $this->toDbDateTime($p['createdAt'] ?? null),
                'text' => $p['text'] ?? null,
                'author_user_id' => $p['authorUserId'] ?? null,
                'author_name' => $p['authorName'] ?? null,
                'author_username' => $p['authorUsername'] ?? null,
                'author_email' => $p['authorEmail'] ?? null,
                'team_id' => $p['teamId'] ?? null,
                'team_name' => $p['teamName'] ?? null,
                'published_in_period' => $p['publishedInPeriod'] ?? null,
                'likes_in_period' => $p['likesInPeriod'] ?? null,
                'dislikes_in_period' => $p['dislikesInPeriod'] ?? null,
                'shares_in_period' => $p['sharesInPeriod'] ?? null,
                'other_reactions_in_period' => $p['otherReactionsInPeriod'] ?? null,
                'reactions_in_period' => $p['reactionsInPeriod'] ?? null,
                'comments_in_period' => $p['commentsInPeriod'] ?? null,
                'interactions_in_period' => $p['interactionsInPeriod'] ?? null,
                'last_interaction_at' => $this->toDbDateTime($p['lastInteractionAt'] ?? null),
                'viewers_in_period' => $p['viewersInPeriod'] ?? null,
                'total_reactions' => $p['totalReactions'] ?? null,
                'total_comments' => $p['totalComments'] ?? null,
                'total_view_count' => $p['totalViewCount'] ?? null,
            ], $posts);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('team_app_social_post_snapshots')->insert($chunk);
            }
        });
    }

    /** @param  list<array<string, mixed>>  $followed */
    private function storeFollowed(string $today, array $followed): void
    {
        DB::transaction(function () use ($today, $followed) {
            DB::table('team_app_social_follow_snapshots')
                ->where('captured_at', $today)->where('period', self::PERIOD)->delete();

            if ($followed === []) {
                return;
            }

            $rows = array_map(fn (array $a) => [
                'captured_at' => $today,
                'period' => self::PERIOD,
                'rank' => $a['rank'] ?? 0,
                'account_type' => $a['accountType'] ?? '',
                'account_id' => $a['accountId'] ?? null,
                'display_name' => $a['displayName'] ?? null,
                'username' => $a['username'] ?? null,
                'sport_type' => $a['sportType'] ?? null,
                'email' => $a['email'] ?? null,
                'phone' => $a['phone'] ?? null,
                'new_followers' => $a['newFollowers'] ?? null,
                'total_followers' => $a['totalFollowers'] ?? null,
            ], $followed);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('team_app_social_follow_snapshots')->insert($chunk);
            }
        });
    }

    /**
     * The API returns ISO-8601 datetimes (e.g. "2026-08-31T12:00:00Z"),
     * which a raw DB::table()->insert() would otherwise write verbatim into
     * a DATETIME column — MySQL doesn't parse the 'T'/'Z' separators, so
     * this normalizes to the plain 'Y-m-d H:i:s' form Eloquent/MySQL both
     * expect. Null (missing/unparseable) rather than throwing — one bad
     * timestamp on one leaderboard row shouldn't abort the whole capture.
     */
    private function toDbDateTime(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
