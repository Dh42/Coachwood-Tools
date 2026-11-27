<?php

namespace App\Console\Commands;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqListingSnapshot;
use App\Models\CdqScore;
use App\Services\Cdq\CdqScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CDQ Phase 3 — runs the deterministic scoring engine over stored
 * snapshots. Scoring is pure/replayable (no live SP-API calls), so this
 * command only ever reads cdq_listing_snapshot + cdq_check_config*.
 *
 * --current and --as-of are two distinct rescore modes, both scoped to
 * "one snapshot per ASIN":
 *   --current (default): today's picture — each ASIN's latest snapshot,
 *   scored against the currently-effective check-config revision.
 *   --as-of=YYYY-MM-DD: a historical point-in-time picture — each ASIN's
 *   latest snapshot as of that date, scored against whichever check-config
 *   revision was effective on that date. Lets a later report explain "why
 *   did this score change" against a real prior config, not just prior
 *   content.
 * --snapshot=ID scores one specific snapshot row directly, bypassing the
 * per-ASIN "latest" resolution — for spot-checking or golden-fixture use.
 * --rescore-all forces every targeted snapshot to be recomputed even if a
 * (snapshot, config version) score already exists; without it, already-
 * scored pairs are skipped (scoring is deterministic, so a repeat run is a
 * no-op by default, same dedup spirit as cdq:snapshot's content_hash).
 */
class CdqScoreCommand extends Command
{
    protected $signature = 'cdq:score {--snapshot=} {--rescore-all} {--as-of=} {--current}';

    protected $description = 'CDQ Phase 3 — scores stored listing snapshots against a check-config revision';

    public function handle(): int
    {
        $asOf = $this->option('as-of') ? Carbon::parse($this->option('as-of')) : null;

        $version = $asOf
            ? CdqCheckConfigVersion::where('effective_from', '<=', $asOf)->orderByDesc('effective_from')->first()
            : CdqCheckConfigVersion::orderByDesc('effective_from')->first();

        if ($version === null) {
            $this->error($asOf ? "No check-config revision was effective as of {$asOf->toDateString()}." : 'No check-config revision exists — run the CdqCheckConfigSeeder first.');

            return self::FAILURE;
        }

        $snapshots = $this->resolveSnapshots($asOf);
        if ($snapshots->isEmpty()) {
            $this->error('No matching snapshot(s) found.');

            return self::FAILURE;
        }

        $rescoreAll = (bool) $this->option('rescore-all');
        $service = new CdqScoringService($version);

        $scored = 0;
        $skipped = 0;
        $failed = [];

        foreach ($snapshots as $snapshot) {
            if (! $rescoreAll && CdqScore::where('snapshot_id', $snapshot->id)->where('check_config_version_id', $version->id)->exists()) {
                $skipped++;

                continue;
            }

            try {
                $service->score($snapshot);
                $scored++;
            } catch (\Throwable $e) {
                $failed[] = "{$snapshot->asin} (snapshot #{$snapshot->id}): {$e->getMessage()}";
            }
        }

        $this->info("Scored {$scored} snapshot(s) against config revision #{$version->id}, {$skipped} already scored and skipped.");
        if ($failed) {
            $this->warn('Failed: '.implode('; ', $failed));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function resolveSnapshots(?Carbon $asOf): Collection
    {
        if ($snapshotId = $this->option('snapshot')) {
            $snapshot = CdqListingSnapshot::find($snapshotId);

            return $snapshot ? collect([$snapshot]) : collect();
        }

        $query = CdqListingSnapshot::query();
        if ($asOf) {
            $query->where('captured_at', '<=', $asOf);
        }

        // Latest snapshot per (asin, marketplace_id) — MAX(id) is a valid
        // proxy for "latest" since cdq_listing_snapshot is append-only and
        // ids are assigned in capture order.
        $latestIds = (clone $query)
            ->selectRaw('MAX(id) as id')
            ->groupBy('asin', 'marketplace_id')
            ->pluck('id');

        return CdqListingSnapshot::whereIn('id', $latestIds)->get();
    }
}
