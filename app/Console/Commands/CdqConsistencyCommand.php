<?php

namespace App\Console\Commands;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqListingSnapshot;
use App\Services\Cdq\CdqConsistencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * CDQ Phase 4 — the CONSIST dimension. Requires cdq:score to have already
 * run for the targeted snapshot(s) under the current check-config revision
 * (fills in/updates that existing cdq_score row rather than creating a new
 * one). See CdqConsistencyService for the Batches-API-vs-synchronous scope
 * note — this command always makes synchronous per-ASIN calls.
 */
class CdqConsistencyCommand extends Command
{
    protected $signature = 'cdq:consistency {--asin=} {--snapshot=} {--prompt-version=v1}';

    protected $description = 'CDQ Phase 4 — evaluates the CONSIST dimension (LLM cross-field consistency) and updates existing cdq_score rows';

    public function handle(): int
    {
        $version = CdqCheckConfigVersion::orderByDesc('effective_from')->first();
        if ($version === null) {
            $this->error('No check-config revision exists — run cdq:score first.');

            return self::FAILURE;
        }

        $snapshots = $this->resolveSnapshots($version);
        if ($snapshots->isEmpty()) {
            $this->error('No matching snapshot(s) found.');

            return self::FAILURE;
        }

        $service = new CdqConsistencyService($version, promptVersion: $this->option('prompt-version'));

        $evaluated = 0;
        $skipped = 0;
        $notChecked = 0;
        $failed = [];

        foreach ($snapshots as $snapshot) {
            try {
                $score = $service->evaluate($snapshot);
                match (true) {
                    in_array($score->grade, ['U', 'D'], true) => $skipped++,
                    $service->lastEvaluationWasChecked() => $evaluated++,
                    default => $notChecked++,
                };
            } catch (\RuntimeException $e) {
                $failed[] = "{$snapshot->asin}: {$e->getMessage()}";
            }
        }

        $this->info("Evaluated {$evaluated} snapshot(s), {$skipped} skipped (already Grade U/D — CONSIST doesn't apply).");
        if ($notChecked > 0) {
            // Found issue 13: this used to pass silently with full credit.
            $this->error("{$notChecked} listing(s) not checked: Anthropic didn't give a usable answer (is ANTHROPIC_API_KEY set?). Their consistency score stays provisional and shows as \"Not checked\".");
        }
        if ($failed) {
            $this->warn('Failed: '.implode('; ', $failed));
        }

        return $failed || $notChecked > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveSnapshots(CdqCheckConfigVersion $version): Collection
    {
        if ($snapshotId = $this->option('snapshot')) {
            $snapshot = CdqListingSnapshot::find($snapshotId);

            return $snapshot ? collect([$snapshot]) : collect();
        }

        $query = CdqListingSnapshot::query();
        if ($asin = $this->option('asin')) {
            $query->where('asin', $asin);
        }

        $latestScoredIds = (clone $query)
            ->whereIn('id', function ($q) {
                $q->selectRaw('MAX(cls.id)')
                    ->from('cdq_listing_snapshot as cls')
                    ->groupBy('cls.asin', 'cls.marketplace_id');
            })
            ->whereExists(function ($q) use ($version) {
                $q->selectRaw(1)->from('cdq_score')
                    ->whereColumn('cdq_score.snapshot_id', 'cdq_listing_snapshot.id')
                    ->where('cdq_score.check_config_version_id', $version->id);
            })
            ->pluck('id');

        return CdqListingSnapshot::whereIn('id', $latestScoredIds)->get();
    }
}
