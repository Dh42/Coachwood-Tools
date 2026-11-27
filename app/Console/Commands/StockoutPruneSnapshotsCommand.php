<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes inventory_snapshots older than stockout.snapshot_retention_days.
 *
 * Built for a shared server (the 2026-11-16 incident): it walks the primary
 * key from the oldest row and deletes by id range, so it never scans the
 * table or needs an index on a date column. Ids grow with created_at, so the
 * first row newer than the cutoff ends the run. Batches are small, with a
 * pause between them, and a run stops after --max-batches; a large backlog
 * is cleared over several hourly runs rather than in one long burst.
 */
class StockoutPruneSnapshotsCommand extends Command
{
    protected $signature = 'stockout:prune-snapshots
        {--days= : Keep this many days (default stockout.snapshot_retention_days)}
        {--batch=5000 : Rows per delete}
        {--max-batches=200 : Stop after this many deletes; the next run carries on}
        {--pause-ms=200 : Pause between deletes, to leave disk time for other sites}';

    protected $description = 'Deletes inventory snapshots older than the retention window, in small batches';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('stockout.snapshot_retention_days'));
        $batch = max(1, (int) $this->option('batch'));
        $maxBatches = max(1, (int) $this->option('max-batches'));
        $pauseMs = max(0, (int) $this->option('pause-ms'));

        if ($days < 1) {
            $this->error("Refusing to prune with a retention of {$days} days.");

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');
        $deleted = 0;
        $batches = 0;

        while ($batches < $maxBatches) {
            $rows = DB::table('inventory_snapshots')->orderBy('id')->limit($batch)->get(['id', 'created_at']);

            $firstId = $rows->first()?->id;
            $lastOldId = null;
            foreach ($rows as $row) {
                if ((string) $row->created_at >= $cutoff) {
                    break;
                }
                $lastOldId = $row->id;
            }

            if ($lastOldId === null) {
                break;
            }

            $deleted += DB::table('inventory_snapshots')->whereBetween('id', [$firstId, $lastOldId])->delete();
            $batches++;

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        $more = $batches === $maxBatches ? ' Stopped at --max-batches; the next run continues.' : '';
        $this->info("Deleted {$deleted} snapshot(s) older than {$days} days in {$batches} batch(es).{$more}");

        return self::SUCCESS;
    }
}
