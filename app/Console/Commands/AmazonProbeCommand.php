<?php

namespace App\Console\Commands;

use App\Amazon\Probe\Capability;
use App\Amazon\Probe\ProbeCatalog;
use App\Amazon\Probe\ProbeResult;
use App\Amazon\Probe\ProbeRunner;
use App\Models\AmazonCapability;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 0 capability probe (spec section 8, docs/phase-0-plan.md section 4).
 * Read-only: it creates report and Data Kiosk query requests and reads
 * their results. Lesley runs it on the server, at a quiet hour.
 */
class AmazonProbeCommand extends Command
{
    public const SUMMARY_PATH = 'probe/summary.json';

    protected $signature = 'amazon:probe
        {--asin= : One BioSteel ASIN sold on Amazon.ca, used only for the seller offers check. It is never written to any output}
        {--only=* : Run only these capability keys (see --list)}
        {--list : List the capabilities and exit without calling Amazon}';

    protected $description = 'Read-only SP-API capability probe: calls each capability once and records what the account returns';

    public function handle(): int
    {
        $asin = $this->option('asin') ? strtoupper(trim($this->option('asin'))) : null;
        $capabilities = ProbeCatalog::build(CarbonImmutable::now(), $asin);

        if ($only = $this->option('only')) {
            $known = array_map(fn (Capability $c) => $c->key, $capabilities);
            if ($unknown = array_diff($only, $known)) {
                $this->error('Unknown capability key(s): '.implode(', ', $unknown).'. Use --list.');

                return self::INVALID;
            }
            $capabilities = array_values(array_filter($capabilities, fn (Capability $c) => in_array($c->key, $only, true)));
        }

        if ($this->option('list')) {
            $this->table(['Key', 'What', 'Wanted for', 'Account'], array_map(
                fn (Capability $c) => [$c->key, $c->label, $c->wantedFor, $c->account], $capabilities,
            ));

            return self::SUCCESS;
        }

        $missing = array_keys(array_filter([
            'SP_API_CLIENT_ID' => config('services.sp_api.client_id'),
            'SP_API_CLIENT_SECRET' => config('services.sp_api.client_secret'),
            'SP_API_VENDOR_REFRESH_TOKEN' => config('services.sp_api.vendor_refresh_token'),
        ], fn ($value) => empty($value)));
        if ($missing !== []) {
            $this->error('Missing in .env: '.implode(', ', $missing).'. Then run php artisan optimize.');

            return self::FAILURE;
        }
        if (empty(config('services.sp_api.seller_refresh_token'))) {
            $this->warn('SP_API_SELLER_REFRESH_TOKEN is not set; the seller checks will be skipped.');
        }

        $this->info(sprintf(
            'amazon:probe will make %d read-only requests to the live SP-API, plus status checks every %ds for up to %d minutes.',
            count($capabilities), ProbeRunner::POLL_INTERVAL_SECONDS, intdiv(ProbeRunner::MAX_WAIT_SECONDS, 60),
        ));
        if (! $this->confirm('Continue?', true)) {
            return self::FAILURE;
        }

        $runner = $this->laravel->makeWith(ProbeRunner::class, ['log' => fn (string $line) => $this->line($line)]);
        $results = $runner->run($capabilities, array_filter([$asin]));

        $probedAt = now();
        foreach ($results as $result) {
            $this->store($result, $probedAt);
        }
        $summaryPath = $this->writeSummary();

        $this->newLine();
        $this->table(['Key', 'Status', 'HTTP', 'Rows', 'Note'], array_map(fn (ProbeResult $r) => [
            $r->capability->key,
            $r->status,
            $r->httpStatus ?? '',
            $r->rowCount ?? '',
            Str::limit($this->note($r), 70),
        ], $results));

        $counts = array_count_values(array_map(fn (ProbeResult $r) => $r->status, $results));
        ksort($counts);
        $this->info('Done: '.collect($counts)->map(fn ($n, $s) => "{$n} {$s}")->implode(', ').'.');
        $this->line("Summary (safe to send; no ASINs, amounts or credentials): {$summaryPath}");
        $this->line('Raw responses (keep on the server): '.Storage::disk('local')->path(ProbeRunner::RAW_DIR));

        return self::SUCCESS;
    }

    private function store(ProbeResult $result, $probedAt): void
    {
        $c = $result->capability;

        AmazonCapability::query()->updateOrCreate(['key' => $c->key], [
            'label' => $c->label,
            'family' => $c->family,
            'account' => $c->account,
            'marketplace_id' => $c->marketplaceId,
            'status' => $result->status,
            'http_status' => $result->httpStatus,
            'error_code' => $result->errorCode !== null ? Str::limit($result->errorCode, 97) : null,
            'error_message' => $result->errorMessage,
            'fields_requested' => $result->fieldsRequested,
            'fields_rejected' => $result->fieldsRejected,
            'field_stats' => $result->fieldStats,
            'row_count' => $result->rowCount,
            'notes' => $result->notes + ['wanted_for' => $c->wantedFor],
            'probed_at' => $probedAt,
        ]);
    }

    /** The whole registry, so a partial re-run (--only) still gives a complete summary. */
    private function writeSummary(): string
    {
        $rows = AmazonCapability::query()->orderBy('key')->get()->map(fn (AmazonCapability $c) => [
            'key' => $c->key,
            'label' => $c->label,
            'family' => $c->family,
            'account' => $c->account,
            'marketplace_id' => $c->marketplace_id,
            'status' => $c->status,
            'http_status' => $c->http_status,
            'error_code' => $c->error_code,
            'error_message' => $c->error_message,
            'fields_requested' => $c->fields_requested,
            'fields_rejected' => $c->fields_rejected,
            'row_count' => $c->row_count,
            'notes' => $c->notes,
            'field_stats' => $c->field_stats,
            'probed_at' => $c->probed_at?->toIso8601String(),
        ])->all();

        Storage::disk('local')->put(self::SUMMARY_PATH, json_encode([
            'generated_at' => now()->toIso8601String(),
            'app_url' => config('app.url'),
            'vendor_marketplace_id' => config('services.sp_api.vendor_marketplace_id'),
            'statuses' => [
                'pass' => 'Returned data',
                'partial' => 'Returned data after Amazon rejected some fields (see fields_rejected)',
                'empty' => 'Worked, but no data for the period',
                'role_missing' => 'HTTP 401/403: the app lacks the role, or the API is closed to this account',
                'rate_limited' => 'HTTP 429 twice',
                'fail' => 'Any other error',
                'timeout' => 'Not finished within 45 minutes',
                'skipped' => 'Not attempted (see notes.skip_reason)',
            ],
            'capabilities' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Storage::disk('local')->path(self::SUMMARY_PATH);
    }

    private function note(ProbeResult $r): string
    {
        return match (true) {
            $r->errorMessage !== null => $r->errorMessage,
            $r->fieldsRejected !== [] => 'rejected: '.implode(', ', $r->fieldsRejected),
            isset($r->notes['skip_reason']) => $r->notes['skip_reason'],
            ! empty($r->notes['fields_never_filled']) => 'never filled: '.implode(', ', $r->notes['fields_never_filled']),
            ! empty($r->notes['expected_fields_never_filled']) => 'never filled: '.implode(', ', $r->notes['expected_fields_never_filled']),
            default => '',
        };
    }
}
