<?php

namespace App\Amazon\Backfill;

use App\Amazon\Probe\DataKioskQuery;
use App\Amazon\Probe\ProbeClient;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Runs the history grab one query at a time: submit, wait for Amazon,
 * download, save. Data Kiosk allows few queries at once (the probe hit its
 * quota after nine), so nothing runs in parallel.
 *
 * Read-only: it uses ProbeClient, which refuses every write except
 * creating a Data Kiosk query.
 *
 * Each finished query is saved straight away, and the manifest is rewritten
 * after every query. A re-run skips queries already saved, so an
 * interrupted run picks up where it stopped.
 */
final class HistoryGrabRunner
{
    public const DIR = 'amazon/history-2024';

    public const MANIFEST = self::DIR.'/manifest.json';

    public const POLL_INTERVAL_SECONDS = 30;

    public const MAX_WAIT_SECONDS = 2700;

    public const RATE_LIMIT_PAUSE_SECONDS = 65;

    public const SUBMIT_ATTEMPTS = 4;

    private const ERROR_MESSAGE_LIMIT = 300;

    private readonly Closure $sleep;

    private readonly Closure $log;

    public function __construct(
        private readonly ProbeClient $client,
        ?Closure $sleep = null,
        ?Closure $log = null,
    ) {
        $this->sleep = $sleep ?? fn (int $seconds) => sleep($seconds);
        $this->log = $log ?? fn (string $line) => null;
    }

    public static function path(string $key): string
    {
        return self::DIR.'/'.$key.'.jsonl.gz';
    }

    /** @return array<string, array<string, mixed>> */
    public static function manifest(): array
    {
        $json = Storage::disk('local')->get(self::MANIFEST);

        return is_string($json) ? (json_decode($json, true)['queries'] ?? []) : [];
    }

    /**
     * @param  array<string, DataKioskQuery>  $plan
     * @return array<string, array<string, mixed>> the manifest entries for this run
     */
    public function run(array $plan, string $refreshToken, bool $force = false): array
    {
        $manifest = self::manifest();
        $results = [];

        foreach ($plan as $key => $query) {
            if (! $force && ($manifest[$key]['status'] ?? null) === 'saved' && Storage::disk('local')->exists(self::path($key))) {
                ($this->log)(sprintf('  %-30s already saved; skipped (use --force to fetch again)', $key));
                $results[$key] = $manifest[$key];

                continue;
            }

            ($this->log)(sprintf('  %-30s %s to %s, submitting', $key, $query->startDate, $query->endDate));
            try {
                $entry = $this->runOne($key, $query, $refreshToken);
            } catch (Throwable $e) {
                $entry = $this->entry($query) + ['status' => 'fail', 'error' => $this->clip(class_basename($e).': '.$e->getMessage())];
            }

            $entry['fetched_at'] = now()->toIso8601String();
            $manifest[$key] = $results[$key] = $entry;
            $this->writeManifest($manifest);

            ($this->log)(sprintf('  %-30s %s%s', $key, $entry['status'], isset($entry['rows']) ? " ({$entry['rows']} rows)" : ''));
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function runOne(string $key, DataKioskQuery $query, string $refreshToken): array
    {
        $rejected = [];
        $response = $this->submit($query, $refreshToken);

        if ($response['status'] === 400) {
            $rejected = DataKioskQuery::undefinedFields($this->errorText($response['body']));
            if ($rejected !== []) {
                $query = $query->without($rejected);
                if ($query->isEmpty()) {
                    return $this->entry($query) + ['status' => 'fail', 'fields_rejected' => $rejected, 'error' => 'Amazon rejected every requested field.'];
                }
                ($this->log)(sprintf('  %-30s Amazon rejected %s; resubmitting without them', $key, implode(', ', $rejected)));
                $response = $this->submit($query, $refreshToken);
            }
        }

        $entry = $this->entry($query) + ['fields_rejected' => $rejected];

        $queryId = $response['body']['queryId'] ?? null;
        if ($response['status'] < 200 || $response['status'] >= 300 || $queryId === null) {
            return $entry + ['status' => 'fail', 'http_status' => $response['status'], 'error' => $this->clip($this->errorText($response['body']) ?: 'HTTP '.$response['status'])];
        }

        $body = $this->waitFor($key, $queryId, $refreshToken);
        if ($body === null) {
            return $entry + ['status' => 'timeout', 'error' => 'Not finished after '.intdiv(self::MAX_WAIT_SECONDS, 60).' minutes.'];
        }

        $status = $body['processingStatus'] ?? null;
        if ($status === 'CANCELLED') {
            return $entry + ['status' => 'empty'];
        }
        if ($status === 'FATAL') {
            $errorDocumentId = $body['errorDocumentId'] ?? null;
            $detail = $errorDocumentId ? $this->download($errorDocumentId, $refreshToken) : 'Query ended FATAL with no error document.';
            $decoded = json_decode($detail, true);
            $message = is_array($decoded) ? ($this->errorText($decoded) ?: $detail) : $detail;

            return $entry + ['status' => 'fail', 'error' => $this->clip($message)];
        }
        if ($status !== 'DONE') {
            return $entry + ['status' => 'fail', 'error' => "Query ended with status {$status}."];
        }

        $documentId = $body['dataDocumentId'] ?? null;
        if ($documentId === null) {
            return $entry + ['status' => 'empty', 'rows' => 0];
        }

        $content = $this->download($documentId, $refreshToken);
        Storage::disk('local')->put(self::path($key), gzencode($content));

        return $entry + ['status' => 'saved', 'file' => self::path($key)] + self::describe($content);
    }

    /** Submits, waiting out a 429 a bounded number of times. */
    private function submit(DataKioskQuery $query, string $refreshToken): array
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->client->call('POST', '/dataKiosk/2023-11-15/queries', [], ['query' => $query->toGraphQl()], $refreshToken);
            if ($response['status'] !== 429 || $attempt >= self::SUBMIT_ATTEMPTS) {
                return $response;
            }
            ($this->log)('  rate limited; waiting '.self::RATE_LIMIT_PAUSE_SECONDS.'s');
            ($this->sleep)(self::RATE_LIMIT_PAUSE_SECONDS);
        }
    }

    /** @return ?array the final query status body, or null on timeout */
    private function waitFor(string $key, string $queryId, string $refreshToken): ?array
    {
        for ($elapsed = 0; $elapsed < self::MAX_WAIT_SECONDS;) {
            ($this->sleep)(self::POLL_INTERVAL_SECONDS);
            $elapsed += self::POLL_INTERVAL_SECONDS;

            $response = $this->client->call('GET', '/dataKiosk/2023-11-15/queries/'.$queryId, [], null, $refreshToken);
            if ($response['status'] === 429) {
                continue;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new RuntimeException('Status check failed with HTTP '.$response['status'].'.');
            }

            $status = $response['body']['processingStatus'] ?? null;
            if (! in_array($status, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
                return $response['body'];
            }
            if ($elapsed % 300 === 0) {
                ($this->log)(sprintf('  %-30s still running after %d min', $key, intdiv($elapsed, 60)));
            }
        }

        return null;
    }

    private function download(string $documentId, string $refreshToken): string
    {
        $meta = $this->client->call('GET', '/dataKiosk/2023-11-15/documents/'.$documentId, [], null, $refreshToken);
        $url = $meta['body']['documentUrl'] ?? null;
        if ($url === null) {
            throw new RuntimeException('Document had no download URL (HTTP '.$meta['status'].').');
        }

        $response = Http::timeout(300)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Document download failed with HTTP '.$response->status().'.');
        }

        $content = $response->body();
        $decoded = @gzdecode($content);

        return $decoded !== false ? $decoded : $content;
    }

    /**
     * Counts only: periods, rows and the date range. No values.
     *
     * @return array{periods: int, rows: int, period_range: ?array{0: string, 1: string}}
     */
    public static function describe(string $jsonl): array
    {
        $periods = $rows = 0;
        $min = $max = null;

        foreach (preg_split('/\r?\n/', $jsonl) as $line) {
            $line = json_decode(trim($line), true);
            if (! is_array($line)) {
                continue;
            }
            $periods++;
            $rows += count($line['metrics'] ?? []);
            foreach (['startDate', 'endDate'] as $field) {
                if (isset($line[$field])) {
                    $min = $min === null || $line[$field] < $min ? $line[$field] : $min;
                    $max = $max === null || $line[$field] > $max ? $line[$field] : $max;
                }
            }
        }

        return ['periods' => $periods, 'rows' => $rows, 'period_range' => $min === null ? null : [$min, $max]];
    }

    /** @return array<string, mixed> */
    private function entry(DataKioskQuery $query): array
    {
        return [
            'grain' => $query->aggregateBy,
            'start' => $query->startDate,
            'end' => $query->endDate,
            'group_by' => $query->groupBy,
            'query' => $query->toGraphQl(),
        ];
    }

    private function writeManifest(array $manifest): void
    {
        Storage::disk('local')->put(self::MANIFEST, json_encode([
            'about' => 'One-off Data Kiosk history grab (docs/context.md, 2026-11-26). Raw files stay on the server until Phase 1 loads them.',
            'updated_at' => now()->toIso8601String(),
            'queries' => $manifest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function errorText(?array $body): string
    {
        $parts = [];
        foreach ($body['errors'] ?? [] as $error) {
            $parts[] = trim(($error['message'] ?? '').' '.($error['details'] ?? ''));
        }

        return implode(' | ', array_filter($parts));
    }

    /** Removes ASINs and long IDs from Amazon's messages and caps their length. */
    private function clip(string $message): string
    {
        $message = preg_replace('/\bB0[0-9A-Z]{8}\b/', '[asin]', $message);
        $message = preg_replace('/\b\d{9,}\b/', '[id]', $message);
        $message = trim(preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message) > self::ERROR_MESSAGE_LIMIT ? mb_substr($message, 0, self::ERROR_MESSAGE_LIMIT).'…' : $message;
    }
}
