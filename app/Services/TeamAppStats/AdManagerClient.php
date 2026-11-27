<?php

namespace App\Services\TeamAppStats;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Ad Manager (Beta API) — ported from the legacy standalone tool's
 * fetch_ad_stats(). Runs the pre-built report (config('team_app_stats.gam_report_name'),
 * created once out-of-band against the live API — this app never creates or
 * modifies it), polls until done, then reads back yesterday's row. Its
 * dateRange is "relative: YESTERDAY", so the same report is reused forever
 * rather than recreated per run.
 */
class AdManagerClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/admanager.readonly';

    private const BASE_URL = 'https://admanager.googleapis.com/v1/';

    public function __construct(
        private readonly GoogleServiceAccountAuth $auth,
        private readonly int $pollAttempts = 15,
        private readonly int $pollDelaySeconds = 2,
    ) {}

    /**
     * @return array{date: string, impressions: ?int, clicks: ?int, ad_requests: ?int, viewable_impressions: ?int, measurable_impressions: ?int}|null
     */
    public function fetchYesterdayStats(): ?array
    {
        $reportName = (string) config('team_app_stats.gam_report_name');

        $run = $this->call('POST', self::BASE_URL.$reportName.':run');
        $opName = $run->json('name');

        if ($run->failed() || ! is_string($opName)) {
            return null;
        }

        $resultName = $this->pollUntilDone($opName);

        if ($resultName === null) {
            return null;
        }

        $rows = $this->call('GET', self::BASE_URL.$resultName.':fetchRows');
        $row = $rows->json('rows.0');

        if ($rows->failed() || ! is_array($row)) {
            return null;
        }

        return $this->parseRow($row);
    }

    private function pollUntilDone(string $opName): ?string
    {
        for ($i = 0; $i < $this->pollAttempts; $i++) {
            $poll = $this->call('GET', self::BASE_URL.$opName);

            if ($poll->failed()) {
                return null;
            }

            if ($poll->json('done') === true) {
                $resultName = $poll->json('response.reportResult');

                return is_string($resultName) ? $resultName : null;
            }

            if ($i < $this->pollAttempts - 1) {
                sleep($this->pollDelaySeconds);
            }
        }

        return null;
    }

    /**
     * @return array{date: string, impressions: ?int, clicks: ?int, ad_requests: ?int, viewable_impressions: ?int, measurable_impressions: ?int}
     */
    private function parseRow(array $row): array
    {
        $dateInt = (int) ($row['dimensionValues'][0]['intValue'] ?? 0);
        $date = sprintf('%04d-%02d-%02d', intdiv($dateInt, 10000), intdiv($dateInt % 10000, 100), $dateInt % 100);

        $values = array_map(
            fn ($v) => isset($v['intValue']) ? (int) $v['intValue'] : null,
            $row['metricValueGroups'][0]['primaryValues'] ?? []
        );
        [$impressions, $clicks, $adRequests, $viewable, $measurable] = $values + [null, null, null, null, null];

        return [
            'date' => $date,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ad_requests' => $adRequests,
            'viewable_impressions' => $viewable,
            'measurable_impressions' => $measurable,
        ];
    }

    private function call(string $method, string $url): Response
    {
        $request = Http::withToken($this->auth->token(self::SCOPE));

        if ($method === 'POST') {
            // Ad Manager's `:run` RPC takes no request body. send() with no
            // extra options (unlike post(), which always overwrites the
            // body with its own $data argument) is what actually preserves
            // this empty body — matching the legacy client, which posts a
            // truly empty string with no JSON body.
            $request = $request->withBody('', 'application/json');
        }

        return $request->send($method, $url);
    }
}
