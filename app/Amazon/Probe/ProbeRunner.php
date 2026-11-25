<?php

namespace App\Amazon\Probe;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs each capability once (spec section 8):
 *  1. synchronous API calls, one at a time;
 *  2. every report and Data Kiosk query is submitted, with a pause between
 *     submissions;
 *  3. all of them are polled together every 30 seconds, for up to 45 minutes;
 *  4. finished documents are downloaded, described and saved raw.
 *
 * No loop retries. The only repeats are one resubmission of a Data Kiosk
 * query without the fields Amazon rejected, and one retry after a 429.
 */
final class ProbeRunner
{
    public const POLL_INTERVAL_SECONDS = 30;

    public const MAX_WAIT_SECONDS = 2700;

    public const SUBMIT_PAUSE_SECONDS = 2;

    public const POLL_CALL_PAUSE_SECONDS = 1;

    public const RATE_LIMIT_PAUSE_SECONDS = 65;

    public const RAW_DIR = 'probe/raw';

    private const ERROR_MESSAGE_LIMIT = 300;

    private readonly Closure $sleep;

    private readonly Closure $log;

    /** @var array<string, array<string, string>> values captured for dependent calls; never stored. */
    private array $captured = [];

    /** @var list<string> */
    private array $mask = [];

    public function __construct(
        private readonly ProbeClient $client,
        ?Closure $sleep = null,
        ?Closure $log = null,
    ) {
        $this->sleep = $sleep ?? fn (int $seconds) => sleep($seconds);
        $this->log = $log ?? fn (string $line) => null;
    }

    /**
     * @param  list<Capability>  $capabilities
     * @param  list<string>  $mask  strings never to repeat in output (e.g. the --asin value)
     * @return list<ProbeResult>
     */
    public function run(array $capabilities, array $mask = []): array
    {
        $this->mask = array_values(array_filter($mask));
        $results = array_map(fn (Capability $c) => new ProbeResult($c), $capabilities);

        foreach ($results as $result) {
            if (empty($result->capability->refreshToken())) {
                $this->skip($result, "No {$result->capability->account} refresh token is configured.");
            }
        }

        foreach ($results as $result) {
            if ($result->capability->family === Capability::API && $result->isPending()) {
                $this->guard($result, fn () => $this->runApi($result));
                $this->logResult($result);
            }
        }

        $submitted = 0;
        foreach ($results as $result) {
            $family = $result->capability->family;
            if ($family === Capability::API || ! $result->isPending()) {
                continue;
            }
            if ($submitted++ > 0) {
                ($this->sleep)(self::SUBMIT_PAUSE_SECONDS);
            }
            $this->guard($result, fn () => $family === Capability::REPORT ? $this->submitReport($result) : $this->submitKiosk($result));
            ($this->log)(sprintf('  submitted  %-45s %s', $result->capability->key, $result->isPending() ? 'ok' : $result->status));
        }

        $this->pollAll($results);

        return $results;
    }

    private function runApi(ProbeResult $result): void
    {
        $request = $result->capability->request;

        if (! empty($request['skip_reason'])) {
            $this->skip($result, $request['skip_reason']);

            return;
        }

        $path = $request['path'];
        if (isset($request['needs'])) {
            $value = $this->captured[$request['needs']['from']][$request['needs']['value']] ?? null;
            if ($value === null) {
                $this->skip($result, 'Nothing to look up: '.$request['needs']['from'].' returned no '.$request['needs']['value'].'.');

                return;
            }
            $path = str_replace('{'.$request['needs']['value'].'}', rawurlencode($value), $path);
        }

        $response = $this->call($result, 'GET', $path, $request['query'] ?? []);
        if (! $this->accept($result, $response)) {
            return;
        }

        $body = $response['body'] ?? [];
        $this->saveRaw($result, json_encode($body));
        $this->describeRows($result, $body);

        if (isset($request['capture'])) {
            $found = self::firstValue($body, $request['capture']);
            if ($found !== null) {
                $this->captured[$result->capability->key][$request['capture']] = (string) $found;
            }
        }
    }

    private function submitReport(ProbeResult $result): void
    {
        $request = $result->capability->request;
        $result->notes['request'] = $request['options'];

        $response = $this->call($result, 'POST', '/reports/2021-06-30/reports', [], $request['options'] + [
            'reportType' => $request['reportType'],
            'marketplaceIds' => [$result->capability->marketplaceId],
        ]);
        if (! $this->accept($result, $response)) {
            return;
        }

        $result->amazonId = $response['body']['reportId'] ?? null;
        if ($result->amazonId === null) {
            $this->fail($result, 'NoReportId', 'createReport returned no reportId.');
        }
    }

    private function submitKiosk(ProbeResult $result): void
    {
        /** @var DataKioskQuery $query */
        $query = $result->capability->request['query'];
        $result->fieldsRequested = $query->fields();
        $result->notes['request'] = "{$query->view}, {$query->aggregateBy}, {$query->startDate} to {$query->endDate}, grouped by ".(implode(', ', $query->groupBy) ?: 'nothing');

        $response = $this->call($result, 'POST', '/dataKiosk/2023-11-15/queries', [], ['query' => $query->toGraphQl()]);

        if ($response['status'] === 400) {
            $rejected = DataKioskQuery::undefinedFields(self::errorText($response['body']));
            if ($rejected !== []) {
                $result->fieldsRejected = $rejected;
                $query = $query->without($rejected);
                if ($query->isEmpty()) {
                    $this->fail($result, 'FieldUndefined', 'Amazon rejected every requested field.', 400);

                    return;
                }
                ($this->sleep)(self::SUBMIT_PAUSE_SECONDS);
                $response = $this->call($result, 'POST', '/dataKiosk/2023-11-15/queries', [], ['query' => $query->toGraphQl()]);
            }
        }

        if (! $this->accept($result, $response)) {
            return;
        }

        $result->amazonId = $response['body']['queryId'] ?? null;
        if ($result->amazonId === null) {
            $this->fail($result, 'NoQueryId', 'createQuery returned no queryId.');
        }
    }

    /** @param  list<ProbeResult>  $results */
    private function pollAll(array $results): void
    {
        $pending = fn () => array_filter($results, fn (ProbeResult $r) => $r->isPending());
        $elapsed = 0;

        while ($pending() !== []) {
            if ($elapsed >= self::MAX_WAIT_SECONDS) {
                foreach ($pending() as $result) {
                    $result->status = 'timeout';
                    $result->errorMessage = 'Not finished after '.intdiv(self::MAX_WAIT_SECONDS, 60).' minutes.';
                    $this->logResult($result);
                }

                return;
            }

            ($this->sleep)(self::POLL_INTERVAL_SECONDS);
            $elapsed += self::POLL_INTERVAL_SECONDS;

            foreach ($pending() as $result) {
                $this->guard($result, fn () => $result->capability->family === Capability::REPORT ? $this->pollReport($result) : $this->pollKiosk($result));
                if (! $result->isPending()) {
                    $this->logResult($result);
                }
                ($this->sleep)(self::POLL_CALL_PAUSE_SECONDS);
            }

            ($this->log)(sprintf('  %d of %d still running after %d min', count($pending()), count($results), intdiv($elapsed, 60)));
        }
    }

    private function pollReport(ProbeResult $result): void
    {
        $response = $this->client->call('GET', '/reports/2021-06-30/reports/'.$result->amazonId, [], null, $result->capability->refreshToken());
        if ($response['status'] === 429) {
            return; // try again next round
        }
        if (! $this->accept($result, $response)) {
            return;
        }

        $body = $response['body'] ?? [];
        $status = $body['processingStatus'] ?? null;
        if (in_array($status, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
            return;
        }
        $result->notes['amazon_status'] = $status;
        $documentId = $body['reportDocumentId'] ?? null;

        if ($status === 'CANCELLED') {
            $result->status = 'empty';
            $result->notes['meaning'] = 'Amazon cancels a report when the period has no data.';

            return;
        }

        if ($status === 'FATAL') {
            $detail = $documentId ? $this->download($result, '/reports/2021-06-30/documents/'.$documentId, 'url') : null;
            $decoded = $detail !== null ? json_decode($detail, true) : null;
            $this->fail($result, 'FATAL', is_array($decoded) ? ($decoded['errorDetails'] ?? $detail) : ($detail ?? 'Report ended FATAL with no error document.'));

            return;
        }

        if ($status !== 'DONE' || $documentId === null) {
            $this->fail($result, 'UnexpectedStatus', "Report ended with status {$status}.");

            return;
        }

        if (! ($result->capability->request['download'] ?? true)) {
            $meta = $this->client->call('GET', '/reports/2021-06-30/documents/'.$documentId, [], null, $result->capability->refreshToken());
            $result->status = isset($meta['body']['url']) ? 'pass' : 'fail';
            $result->notes['document_ready'] = isset($meta['body']['url']);
            $result->notes['not_downloaded'] = 'Covers the whole marketplace and can be very large, so only its readiness was checked.';

            return;
        }

        $content = $this->download($result, '/reports/2021-06-30/documents/'.$documentId, 'url');
        $this->saveRaw($result, $content);
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            $this->fail($result, 'NotJson', 'Report document is not JSON.');

            return;
        }

        $this->describeRows($result, $decoded, exclude: ['reportSpecification']);

        if (isset($result->capability->request['expect'])) {
            $result->notes['expected_fields_never_filled'] = array_values(array_filter(
                $result->capability->request['expect'],
                fn (string $field) => ($result->fieldStats[$field]['non_empty'] ?? 0) === 0,
            ));
        }
    }

    private function pollKiosk(ProbeResult $result): void
    {
        $response = $this->client->call('GET', '/dataKiosk/2023-11-15/queries/'.$result->amazonId, [], null, $result->capability->refreshToken());
        if ($response['status'] === 429) {
            return;
        }
        if (! $this->accept($result, $response)) {
            return;
        }

        $body = $response['body'] ?? [];
        $status = $body['processingStatus'] ?? null;
        if (in_array($status, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
            return;
        }
        $result->notes['amazon_status'] = $status;

        if ($status === 'CANCELLED') {
            $result->status = 'empty';

            return;
        }

        if ($status === 'FATAL') {
            $errorDocumentId = $body['errorDocumentId'] ?? null;
            $detail = $errorDocumentId ? $this->download($result, '/dataKiosk/2023-11-15/documents/'.$errorDocumentId, 'documentUrl') : null;
            $this->fail($result, 'FATAL', $detail ?? 'Query ended FATAL with no error document.');

            return;
        }

        $documentId = $body['dataDocumentId'] ?? null;
        if ($status !== 'DONE') {
            $this->fail($result, 'UnexpectedStatus', "Query ended with status {$status}.");

            return;
        }
        if ($documentId === null) {
            $result->status = 'empty';
            $result->rowCount = 0;
            $result->notes['meaning'] = 'Amazon returns no document when the query matched no data.';

            return;
        }

        $content = $this->download($result, '/dataKiosk/2023-11-15/documents/'.$documentId, 'documentUrl');
        $this->saveRaw($result, $content);

        $stats = new FieldStats;
        $periods = 0;
        $periodMin = $periodMax = null;
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = json_decode(trim($line), true);
            if (! is_array($line)) {
                continue;
            }
            $periods++;
            foreach (['startDate', 'endDate'] as $field) {
                if (isset($line[$field])) {
                    $periodMin = $periodMin === null || $line[$field] < $periodMin ? $line[$field] : $periodMin;
                    $periodMax = $periodMax === null || $line[$field] > $periodMax ? $line[$field] : $periodMax;
                }
            }
            foreach ($line['metrics'] ?? [] as $entry) {
                $stats->add(($entry['metrics'] ?? []) + ['groupByKey' => $entry['groupByKey'] ?? []]);
            }
        }

        $result->fieldStats = $stats->result();
        $result->rowCount = $stats->rowCount();
        $result->notes['periods'] = $periods;
        $result->notes['period_range'] = $periodMin === null ? null : [$periodMin, $periodMax];

        $filled = [];
        foreach ($result->fieldStats as $path => $field) {
            if (($field['non_empty'] ?? 0) > 0) {
                $filled[str_replace('[]', '', $path)] = true;
            }
        }
        $requested = array_values(array_diff($result->fieldsRequested, self::prefixed($result->fieldsRejected, $result->fieldsRequested)));
        $result->notes['fields_never_filled'] = array_values(array_filter($requested, fn (string $f) => ! isset($filled[$f])));

        $result->status = $result->rowCount === 0 ? 'empty' : ($result->fieldsRejected !== [] ? 'partial' : 'pass');
    }

    /** Requested paths whose last segment, or any segment, was rejected. */
    private static function prefixed(array $rejected, array $requested): array
    {
        return array_values(array_filter($requested, fn (string $path) => array_intersect(explode('.', $path), $rejected) !== []));
    }

    private function describeRows(ProbeResult $result, array $body, array $exclude = []): void
    {
        [$rows, $from] = self::findRows($body, $exclude);
        $result->fieldStats = FieldStats::describe($rows);
        $result->rowCount = count($rows);
        $result->notes['rows_from'] = $from;
        $result->status = $rows === [] ? 'empty' : 'pass';
    }

    /**
     * The largest list of objects within three levels of the body.
     *
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    public static function findRows(array $body, array $exclude = [], string $path = '', int $depth = 0): array
    {
        if (array_is_list($body) && $body !== [] && is_array($body[0]) && ! array_is_list($body[0])) {
            return [$body, $path === '' ? '(root)' : $path];
        }

        $best = [[], null];
        if ($depth >= 3 || array_is_list($body)) {
            return $best;
        }

        foreach ($body as $key => $value) {
            if (! is_array($value) || in_array($key, $exclude, true)) {
                continue;
            }
            $found = self::findRows($value, $exclude, $path === '' ? (string) $key : "{$path}.{$key}", $depth + 1);
            if (count($found[0]) > count($best[0])) {
                $best = $found;
            }
        }

        return $best;
    }

    /** @return array{status: int, body: ?array} */
    private function call(ProbeResult $result, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $response = $this->client->call($method, $path, $query, $body, $result->capability->refreshToken());

        if ($response['status'] === 429) {
            ($this->log)("  rate limited on {$result->capability->key}; waiting ".self::RATE_LIMIT_PAUSE_SECONDS.'s and retrying once');
            ($this->sleep)(self::RATE_LIMIT_PAUSE_SECONDS);
            $response = $this->client->call($method, $path, $query, $body, $result->capability->refreshToken());
        }

        return $response;
    }

    /** Records the HTTP outcome. Returns true for a 2xx response. */
    private function accept(ProbeResult $result, array $response): bool
    {
        $result->httpStatus = $response['status'];

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return true;
        }

        $body = $response['body'] ?? [];
        $result->errorCode = $body['errors'][0]['code'] ?? ($body['error'] ?? null);
        $result->errorMessage = $this->sanitize(self::errorText($body) ?: 'HTTP '.$response['status']);
        $result->status = match (true) {
            in_array($response['status'], [401, 403], true) => 'role_missing',
            $response['status'] === 429 => 'rate_limited',
            default => 'fail',
        };

        return false;
    }

    private function download(ProbeResult $result, string $documentPath, string $urlKey): string
    {
        $meta = $this->client->call('GET', $documentPath, [], null, $result->capability->refreshToken());
        $url = $meta['body'][$urlKey] ?? null;
        if ($url === null) {
            throw new \RuntimeException('Document had no download URL (HTTP '.$meta['status'].').');
        }

        $content = Http::timeout(120)->get($url)->body();
        $decoded = @gzdecode($content);

        return $decoded !== false ? $decoded : $content;
    }

    private function saveRaw(ProbeResult $result, string $content): void
    {
        Storage::disk('local')->put(self::RAW_DIR.'/'.$result->capability->key.'.json.gz', gzencode($content));
    }

    private function guard(ProbeResult $result, Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            $this->fail($result, class_basename($e), $e->getMessage());
        }
    }

    private function fail(ProbeResult $result, string $code, string $message, ?int $httpStatus = null): void
    {
        $result->status = 'fail';
        $result->errorCode = $code;
        $result->errorMessage = $this->sanitize($message);
        $result->httpStatus = $httpStatus ?? $result->httpStatus;
    }

    private function skip(ProbeResult $result, string $reason): void
    {
        $result->status = 'skipped';
        $result->notes['skip_reason'] = $reason;
    }

    private function logResult(ProbeResult $result): void
    {
        ($this->log)(sprintf('  finished   %-45s %s%s', $result->capability->key, $result->status, $result->rowCount !== null ? " ({$result->rowCount} rows)" : ''));
    }

    /** Removes identifiers from Amazon's messages and caps their length. */
    private function sanitize(string $message): string
    {
        foreach ($this->mask as $secret) {
            $message = str_replace($secret, '[masked]', $message);
        }
        $message = preg_replace('/\bB0[0-9A-Z]{8}\b/', '[asin]', $message);
        $message = preg_replace('/\b\d{9,}\b/', '[id]', $message);
        $message = trim(preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message) > self::ERROR_MESSAGE_LIMIT ? mb_substr($message, 0, self::ERROR_MESSAGE_LIMIT).'…' : $message;
    }

    private static function errorText(?array $body): string
    {
        $parts = [];
        foreach ($body['errors'] ?? [] as $error) {
            $parts[] = trim(($error['message'] ?? '').' '.($error['details'] ?? ''));
        }
        if ($parts === [] && isset($body['error_description'])) {
            $parts[] = $body['error_description'];
        }

        return trim(implode(' | ', $parts));
    }

    private static function firstValue(array $body, string $key): mixed
    {
        $found = null;
        array_walk_recursive($body, function ($value, $k) use ($key, &$found) {
            if ($found === null && $k === $key && is_scalar($value)) {
                $found = $value;
            }
        });

        return $found;
    }
}
