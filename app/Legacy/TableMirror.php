<?php

namespace App\Legacy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Makes tables here an exact copy of the old app's, IDs included: rows are
 * upserted on `id`, and rows the old app no longer has are deleted. Used
 * for tables this app only reads in Phase 0 (`products`, `sc_products`) and
 * for tables where the old app's rows replace this app's shadow rows
 * (plan section 6, "The old app wins").
 *
 * Give the tables parents first: upserts run in that order and deletes in
 * reverse, so foreign keys always point at a row that exists.
 */
final class TableMirror
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly ConnectionInterface $legacy,
        private readonly ConnectionInterface $db,
        private readonly int $pauseMilliseconds = 250,
    ) {}

    /**
     * @param  array<string, list<string>>  $tables  table => columns (id first), parents first
     * @return array<string, array{read: int, new: int, updated: int, removed: int}>
     */
    public function mirror(array $tables): array
    {
        $report = [];
        $oldIds = [];

        foreach ($tables as $table => $columns) {
            $report[$table] = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
            $oldIds[$table] = [];
            $first = true;

            $this->legacy->table($table)->select($columns)->orderBy('id')->chunk(self::CHUNK, function (Collection $rows) use ($table, $columns, &$report, &$oldIds, &$first) {
                if (! $first) {
                    $this->pause();
                }
                $first = false;

                $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
                $existing = $this->db->table($table)->whereIn('id', $ids)->count();
                $this->db->table($table)->upsert(
                    $rows->map(fn ($row) => (array) $row)->all(),
                    ['id'],
                    array_values(array_diff($columns, ['id'])),
                );

                array_push($oldIds[$table], ...$ids);
                $report[$table]['read'] += count($ids);
                $report[$table]['updated'] += $existing;
                $report[$table]['new'] += count($ids) - $existing;
            });
        }

        foreach (array_reverse(array_keys($tables)) as $table) {
            $keep = array_flip($oldIds[$table]);
            $gone = $this->db->table($table)->pluck('id')->map(fn ($id) => (int) $id)->reject(fn (int $id) => isset($keep[$id]))->values()->all();
            foreach (array_chunk($gone, self::CHUNK) as $chunk) {
                $this->db->table($table)->whereIn('id', $chunk)->delete();
            }
            $report[$table]['removed'] = count($gone);
        }

        return $report;
    }

    private function pause(): void
    {
        if ($this->pauseMilliseconds > 0) {
            usleep($this->pauseMilliseconds * 1000);
        }
    }
}
