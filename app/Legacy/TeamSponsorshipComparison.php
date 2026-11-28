<?php

namespace App\Legacy;

use Illuminate\Support\Facades\DB;

/**
 * `compare:team-sponsorship`: the Stats page's figures, worked out the same
 * way against the old database and this one, and every row of the three
 * tables (plan section 2, rule 5). The import mirrors the tables, so right
 * after it everything should match. Counts and hashes only: the tables hold
 * coaches' emails.
 */
final class TeamSponsorshipComparison
{
    /**
     * @return list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>
     */
    public function run(): array
    {
        $sections = ['Stats page figures' => fn (?string $connection) => $this->figures($connection)];
        foreach (MirrorImports::TEAM_SPONSORSHIP as $table => $columns) {
            $sections[$table] = fn (?string $connection) => DB::connection($connection)->table($table)->orderBy('id')->get($columns)
                ->map(fn ($row) => RowHasher::normalise((array) $row))->all();
        }

        $out = [];
        foreach ($sections as $name => $rows) {
            $old = $rows('legacy');
            $new = $rows(null);
            $oldHash = RowHasher::hash($old);
            $newHash = RowHasher::hash($new);
            $out[] = ['section' => $name, 'old_rows' => count($old), 'new_rows' => count($new), 'old_hash' => $oldHash, 'new_hash' => $newHash, 'match' => $oldHash === $newHash];
        }

        return $out;
    }

    /**
     * One row per figure: the totals, sponsorships per month, units per
     * product per month, and the activity sums behind the reach tiles. Grouped
     * in PHP so both databases give the same shape.
     *
     * @return list<array<string, mixed>>
     */
    private function figures(?string $connection): array
    {
        $db = DB::connection($connection);
        $orders = $db->table('orders')->orderBy('id')->get(['id', 'email', 'sent_at']);
        $month = fn ($sentAt) => substr((string) $sentAt, 0, 7);

        $rows = [
            ['figure' => 'total', 'value' => $orders->count()],
            ['figure' => 'unique requesters', 'value' => $orders->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->unique()->count()],
        ];
        foreach ($orders->groupBy(fn ($o) => $month($o->sent_at))->sortKeys() as $key => $group) {
            $rows[] = ['figure' => "orders {$key}", 'value' => $group->count()];
        }

        $monthOf = $orders->mapWithKeys(fn ($o) => [(int) $o->id => $month($o->sent_at)]);
        $units = [];
        foreach ($db->table('order_items')->orderBy('id')->get(['order_id', 'product_label', 'quantity']) as $item) {
            $key = ($monthOf[(int) $item->order_id] ?? '?').' '.$item->product_label;
            $units[$key] = ($units[$key] ?? 0) + (int) $item->quantity;
        }
        ksort($units);
        foreach ($units as $key => $quantity) {
            $rows[] = ['figure' => "units {$key}", 'value' => $quantity];
        }

        $activity = $db->table('team_sponsorship_activity');
        foreach (['emails_count', 'events_count', 'games_count', 'practices_count'] as $column) {
            $rows[] = ['figure' => "sum {$column}", 'value' => (int) (clone $activity)->sum($column)];
        }
        $rows[] = ['figure' => 'teams with no events', 'value' => (clone $activity)->where('events_count', 0)->count()];

        return $rows;
    }
}
