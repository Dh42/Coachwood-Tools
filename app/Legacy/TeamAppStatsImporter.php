<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies BioSteel App Stats' tables from the old app (plan section 5.1).
 * Safe to run again; the old app's data wins (decision 2026-11-25), which
 * matters during shadow running, when both apps take the same hourly
 * snapshot.
 *
 * - team_app_snapshots: upserted on captured_at. IDs are this app's own.
 * - team_app_category_snapshots: each imported snapshot's sport rows
 *   brought in line with the old app's, re-mapped to the new snapshot ID
 * - team_app_ad_snapshots: upserted on date
 * - the two social leaderboard tables: upserted on (captured_at, period, rank)
 * - the two backup tables from the old team-totals correction: re-mapped
 *   to the new snapshot IDs and upserted on their keys
 *
 * Reads in chunks with a pause between them, and reports counts only.
 */
final class TeamAppStatsImporter
{
    private const CHUNK = 1000;

    private const POST_COLUMNS = [
        'captured_at', 'period', 'rank', 'post_id', 'post_type', 'visibility', 'visibility_team_id', 'post_created_at',
        'text', 'author_user_id', 'author_name', 'author_username', 'author_email', 'team_id', 'team_name',
        'published_in_period', 'likes_in_period', 'dislikes_in_period', 'shares_in_period', 'other_reactions_in_period',
        'reactions_in_period', 'comments_in_period', 'interactions_in_period', 'last_interaction_at', 'viewers_in_period',
        'total_reactions', 'total_comments', 'total_view_count',
    ];

    private const FOLLOW_COLUMNS = [
        'captured_at', 'period', 'rank', 'account_type', 'account_id', 'display_name', 'username', 'sport_type',
        'email', 'phone', 'new_followers', 'total_followers',
    ];

    /** @var array<string, array{read: int, new: int, updated: int, removed: int}> */
    private array $report = [];

    /** @var array<int, int> old snapshot ID => new snapshot ID */
    private array $snapshotIds = [];

    public function __construct(
        private readonly ConnectionInterface $legacy,
        private readonly ConnectionInterface $db,
        private readonly int $pauseMilliseconds = 250,
    ) {}

    public static function make(): self
    {
        return new self(DB::connection('legacy'), DB::connection());
    }

    /** @return array<string, array{read: int, new: int, updated: int, removed: int}> */
    public function run(bool $dryRun = false): array
    {
        $this->report = [];
        $this->snapshotIds = [];
        $this->db->beginTransaction();

        try {
            $this->importSnapshotsAndCategories();
            $this->importAds();
            $this->importByKey('team_app_social_post_snapshots', self::POST_COLUMNS, ['captured_at', 'period', 'rank']);
            $this->importByKey('team_app_social_follow_snapshots', self::FOLLOW_COLUMNS, ['captured_at', 'period', 'rank']);
            $this->importBackups();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        $dryRun ? $this->db->rollBack() : $this->db->commit();

        return $this->report;
    }

    private function importSnapshotsAndCategories(): void
    {
        $snapshots = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
        $categories = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        $this->eachChunk('team_app_snapshots', ['id', 'captured_at', 'users', 'teams'], function (Collection $rows) use (&$snapshots, &$categories) {
            $capturedAt = $rows->pluck('captured_at')->map(fn ($v) => (string) $v)->all();
            $existing = $this->db->table('team_app_snapshots')->whereIn('captured_at', $capturedAt)->count();

            $this->db->table('team_app_snapshots')->upsert(
                $rows->map(fn ($r) => ['captured_at' => (string) $r->captured_at, 'users' => $r->users, 'teams' => $r->teams])->all(),
                ['captured_at'],
                ['users', 'teams'],
            );

            $newIds = $this->db->table('team_app_snapshots')->whereIn('captured_at', $capturedAt)->pluck('id', 'captured_at');
            foreach ($rows as $row) {
                $this->snapshotIds[(int) $row->id] = (int) $newIds[(string) $row->captured_at];
            }

            $snapshots['read'] += $rows->count();
            $snapshots['updated'] += $existing;
            $snapshots['new'] += $rows->count() - $existing;

            $this->syncCategories($rows->pluck('id')->map(fn ($id) => (int) $id)->all(), $categories);
        });

        $this->report['team_app_snapshots'] = $snapshots;
        $this->report['team_app_category_snapshots'] = $categories;
    }

    /**
     * @param  list<int>  $oldSnapshotIds
     * @param  array{read: int, new: int, updated: int, removed: int}  $report
     */
    private function syncCategories(array $oldSnapshotIds, array &$report): void
    {
        $key = fn (int $snapshotId, string $category) => $snapshotId."\0".$category;

        $wanted = $this->legacy->table('team_app_category_snapshots')->whereIn('snapshot_id', $oldSnapshotIds)->get(['snapshot_id', 'category', 'teams'])
            ->mapWithKeys(function ($r) use ($key) {
                $snapshotId = $this->snapshotIds[(int) $r->snapshot_id];

                return [$key($snapshotId, $r->category) => ['snapshot_id' => $snapshotId, 'category' => $r->category, 'teams' => $r->teams]];
            });

        $newSnapshotIds = array_map(fn (int $id) => $this->snapshotIds[$id], $oldSnapshotIds);
        $here = $this->db->table('team_app_category_snapshots')->whereIn('snapshot_id', $newSnapshotIds)->get(['snapshot_id', 'category', 'teams'])
            ->mapWithKeys(fn ($r) => [$key((int) $r->snapshot_id, $r->category) => (array) $r]);

        foreach ($here->diffKeys($wanted) as $gone) {
            $this->db->table('team_app_category_snapshots')->where('snapshot_id', $gone['snapshot_id'])->where('category', $gone['category'])->delete();
        }
        foreach ($wanted->values()->chunk(self::CHUNK) as $chunk) {
            $this->db->table('team_app_category_snapshots')->upsert($chunk->all(), ['snapshot_id', 'category'], ['teams']);
        }

        $report['read'] += $wanted->count();
        $report['new'] += $wanted->diffKeys($here)->count();
        $report['updated'] += $wanted->intersectByKeys($here)->filter(fn ($row, $k) => (int) $row['teams'] !== (int) $here[$k]['teams'])->count();
        $report['removed'] += $here->diffKeys($wanted)->count();
    }

    private function importAds(): void
    {
        $columns = ['date', 'impressions', 'clicks', 'ad_requests', 'viewable_impressions', 'measurable_impressions'];
        $rows = $this->legacy->table('team_app_ad_snapshots')->orderBy('date')->get($columns)
            ->map(fn ($r) => ['date' => substr((string) $r->date, 0, 10)] + array_slice((array) $r, 1, null, true));
        $existing = $this->db->table('team_app_ad_snapshots')->whereIn('date', $rows->pluck('date'))->count();

        foreach ($rows->chunk(self::CHUNK) as $chunk) {
            $this->db->table('team_app_ad_snapshots')->upsert($chunk->values()->all(), ['date'], array_slice($columns, 1));
        }

        $this->report['team_app_ad_snapshots'] = ['read' => $rows->count(), 'new' => $rows->count() - $existing, 'updated' => $existing, 'removed' => 0];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $uniqueBy
     */
    private function importByKey(string $table, array $columns, array $uniqueBy): void
    {
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        $this->eachChunk($table, ['id', ...$columns], function (Collection $rows) use ($table, $columns, $uniqueBy, &$report) {
            $values = $rows->map(fn ($r) => array_intersect_key((array) $r, array_flip($columns)))->all();
            $existing = 0;
            foreach ($values as $row) {
                $existing += $this->db->table($table)->where(array_intersect_key($row, array_flip($uniqueBy)))->exists() ? 1 : 0;
            }

            $this->db->table($table)->upsert($values, $uniqueBy, array_values(array_diff($columns, $uniqueBy)));

            $report['read'] += $rows->count();
            $report['updated'] += $existing;
            $report['new'] += $rows->count() - $existing;
        });

        $this->report[$table] = $report;
    }

    /** The old team-totals correction's backups, re-mapped to this app's snapshot IDs. */
    private function importBackups(): void
    {
        $teams = $this->legacy->table('team_app_snapshot_teams_backup')->get(['snapshot_id', 'teams'])
            ->filter(fn ($r) => isset($this->snapshotIds[(int) $r->snapshot_id]))
            ->map(fn ($r) => ['snapshot_id' => $this->snapshotIds[(int) $r->snapshot_id], 'teams' => $r->teams]);
        $existing = 0;
        foreach ($teams->chunk(self::CHUNK) as $chunk) {
            $existing += $this->db->table('team_app_snapshot_teams_backup')->whereIn('snapshot_id', $chunk->pluck('snapshot_id'))->count();
            $this->db->table('team_app_snapshot_teams_backup')->upsert($chunk->values()->all(), ['snapshot_id'], ['teams']);
        }
        $this->report['team_app_snapshot_teams_backup'] = ['read' => $teams->count(), 'new' => $teams->count() - $existing, 'updated' => $existing, 'removed' => 0];

        $categories = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
        $this->legacy->table('team_app_category_snapshots_backup')->orderBy('snapshot_id')->orderBy('category')
            ->chunk(self::CHUNK, function (Collection $rows) use (&$categories) {
                $values = $rows->filter(fn ($r) => isset($this->snapshotIds[(int) $r->snapshot_id]))
                    ->map(fn ($r) => ['snapshot_id' => $this->snapshotIds[(int) $r->snapshot_id], 'category' => $r->category, 'teams' => $r->teams])
                    ->values();
                $here = $this->db->table('team_app_category_snapshots_backup')->whereIn('snapshot_id', $values->pluck('snapshot_id')->unique())
                    ->get(['snapshot_id', 'category'])->map(fn ($r) => $r->snapshot_id."\0".$r->category)->flip();
                $existing = $values->filter(fn ($v) => isset($here[$v['snapshot_id']."\0".$v['category']]))->count();

                $this->db->table('team_app_category_snapshots_backup')->upsert($values->all(), ['snapshot_id', 'category'], ['teams']);

                $categories['read'] += $values->count();
                $categories['updated'] += $existing;
                $categories['new'] += $values->count() - $existing;
                $this->pause();
            });
        $this->report['team_app_category_snapshots_backup'] = $categories;
    }

    /** @param  list<string>  $columns */
    private function eachChunk(string $table, array $columns, callable $callback): void
    {
        $first = true;
        $this->legacy->table($table)->select($columns)->orderBy('id')->chunk(self::CHUNK, function (Collection $rows) use ($callback, &$first) {
            if (! $first) {
                $this->pause();
            }
            $first = false;
            $callback($rows);
        });
    }

    private function pause(): void
    {
        if ($this->pauseMilliseconds > 0) {
            usleep($this->pauseMilliseconds * 1000);
        }
    }
}
