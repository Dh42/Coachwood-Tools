<?php

namespace App\Console\Commands;

use App\Amazon\Backfill\HistoryGrabPlan;
use App\Amazon\Backfill\HistoryGrabRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off: saves the Data Kiosk history from before the old app's weekly
 * data starts, while Amazon's two-year limit still allows it. Lesley runs
 * it on the server. It only reads, and it writes nothing to the database.
 * Phase 1 loads the saved files.
 */
class AmazonHistoryGrabCommand extends Command
{
    protected $signature = 'amazon:history-grab
        {--only=* : Run only these query keys (see --list)}
        {--force : Fetch again even if a query is already saved}
        {--list : Show the queries and dates and exit without calling Amazon}';

    protected $description = 'One-off, read-only: save Data Kiosk history for late 2024 before it passes Amazon\'s two-year limit';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $plan = HistoryGrabPlan::build($now);

        if ($plan === []) {
            $this->error('Nothing left to fetch: Amazon now refuses dates before '.HistoryGrabPlan::earliestAllowed($now).', which is past '.HistoryGrabPlan::TARGET_END.'.');

            return self::FAILURE;
        }

        if ($only = $this->option('only')) {
            if ($unknown = array_diff($only, array_keys($plan))) {
                $this->error('Unknown query key(s): '.implode(', ', $unknown).'. Use --list.');

                return self::INVALID;
            }
            $plan = array_intersect_key($plan, array_flip($only));
        }

        $saved = HistoryGrabRunner::manifest();
        $this->line('Amazon accepts dates from '.HistoryGrabPlan::earliestAllowed($now).' today.');
        $this->table(['Key', 'Grain', 'From', 'To', 'Grouped by', 'Already saved'], array_map(fn (string $key) => [
            $key, $plan[$key]->aggregateBy, $plan[$key]->startDate, $plan[$key]->endDate, implode(', ', $plan[$key]->groupBy),
            ($saved[$key]['status'] ?? null) === 'saved' ? 'yes' : '',
        ], array_keys($plan)));

        if ($this->option('list')) {
            return self::SUCCESS;
        }

        $refreshToken = config('services.sp_api.vendor_refresh_token');
        $missing = array_keys(array_filter([
            'SP_API_CLIENT_ID' => config('services.sp_api.client_id'),
            'SP_API_CLIENT_SECRET' => config('services.sp_api.client_secret'),
            'SP_API_VENDOR_REFRESH_TOKEN' => $refreshToken,
        ], fn ($value) => empty($value)));
        if ($missing !== []) {
            $this->error('Missing in .env: '.implode(', ', $missing).'. Then run php artisan optimize.');

            return self::FAILURE;
        }

        $this->info(sprintf('amazon:history-grab will run %d read-only Data Kiosk queries, one at a time.', count($plan)));
        if (! $this->confirm('Continue?', true)) {
            return self::FAILURE;
        }

        $runner = $this->laravel->makeWith(HistoryGrabRunner::class, ['log' => fn (string $line) => $this->line($line)]);
        $results = $runner->run($plan, $refreshToken, (bool) $this->option('force'));

        $this->newLine();
        $this->table(['Key', 'Status', 'Rows', 'Periods', 'Dates', 'Note'], array_map(fn (string $key) => [
            $key,
            $results[$key]['status'],
            $results[$key]['rows'] ?? '',
            $results[$key]['periods'] ?? '',
            isset($results[$key]['period_range']) ? implode(' to ', $results[$key]['period_range']) : '',
            $results[$key]['error'] ?? (($results[$key]['fields_rejected'] ?? []) !== [] ? 'rejected: '.implode(', ', $results[$key]['fields_rejected']) : ''),
        ], array_keys($results)));

        $failed = array_filter($results, fn (array $r) => ! in_array($r['status'], ['saved', 'empty'], true));
        $this->line('Saved in '.Storage::disk('local')->path(HistoryGrabRunner::DIR).'. Keep it on the server; Phase 1 loads it.');
        $this->line('Manifest (safe to send; queries, statuses and counts only): '.Storage::disk('local')->path(HistoryGrabRunner::MANIFEST));

        if ($failed !== []) {
            $this->warn('Not saved: '.implode(', ', array_keys($failed)).'. Run the command again to retry just those.');

            return self::FAILURE;
        }

        $this->info('Done: all '.count($results).' saved.');

        return self::SUCCESS;
    }
}
