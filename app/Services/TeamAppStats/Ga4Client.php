<?php

namespace App\Services\TeamAppStats;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Google Analytics Data API (GA4) — ported from the legacy standalone tool's
 * fetch_ga4_dashboard_data()/fetch_ga4_realtime()/fetch_ga4_sponsor_data().
 *
 * Deliberately never stored in the database: GA4's active-user metrics are
 * deduplicated per query window, so storing daily snapshots and summing them
 * for a custom range would double-count anyone active on multiple days.
 * Always fetched live for the exact requested range — see
 * TeamAppStatsController's ga4* endpoints, called via AJAX from the
 * dashboard, never from a scheduled command.
 */
class Ga4Client
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    private const BASE_URL = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    private const SHARE_LIST_LIMIT = 100;

    // extra rows fetched so the list still reaches SHARE_LIST_LIMIT after
    // the "(not set)" places are dropped
    private const SHARE_LIST_HEADROOM = 20;

    public function __construct(private readonly GoogleServiceAccountAuth $auth) {}

    /**
     * Dashboard breakdown for [from, to] (YYYY-MM-DD, inclusive) plus the
     * previous period's totals for comparison. Two batchRunReports calls
     * (5-request max per batch) instead of one round trip per breakdown.
     */
    public function dashboardData(string $from, string $to): ?array
    {
        $propertyId = (string) config('team_app_stats.ga4_property_id');
        $range = ['startDate' => $from, 'endDate' => $to, 'name' => 'current'];
        $prev = $this->previousPeriod($from, $to);
        $prevRange = ['startDate' => $prev['from'], 'endDate' => $prev['to'], 'name' => 'previous'];
        $base = self::BASE_URL.$propertyId;
        $desc = fn (int $limit) => ['orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]], 'limit' => $limit];

        $batch1 = $this->call("{$base}:batchRunReports", ['requests' => [
            ['dateRanges' => [$range, $prevRange], 'metrics' => [['name' => 'eventCount'], ['name' => 'activeUsers']]],
            ['dateRanges' => [$range], 'dimensions' => [['name' => 'platform']], 'metrics' => [['name' => 'activeUsers']]] + $desc(10),
            ['dateRanges' => [$range], 'dimensions' => [['name' => 'userAgeBracket']], 'metrics' => [['name' => 'activeUsers']]] + $desc(10),
            ['dateRanges' => [$range], 'dimensions' => [['name' => 'userGender']], 'metrics' => [['name' => 'activeUsers']]] + $desc(10),
            ['dateRanges' => [$range], 'dimensions' => [['name' => 'country']], 'metrics' => [['name' => 'activeUsers']]] + $desc(10),
        ]]);
        $reports1 = $batch1->json('reports');

        if ($batch1->failed() || ! is_array($reports1)) {
            return null;
        }

        // city/region carry a second dimension purely as a disambiguating
        // label (London, Ontario vs London, England) and ask GA4 for the
        // report-wide TOTAL so shares are out of every active user in range.
        $withTotal = ['metricAggregations' => ['TOTAL']];

        $batch2 = $this->call("{$base}:batchRunReports", ['requests' => [
            ['dateRanges' => [$range], 'dimensions' => [['name' => 'city'], ['name' => 'region']], 'metrics' => [['name' => 'activeUsers']]] + $desc(self::SHARE_LIST_LIMIT + self::SHARE_LIST_HEADROOM) + $withTotal,
            ['dateRanges' => [$range], 'dimensions' => [['name' => 'region'], ['name' => 'country']], 'metrics' => [['name' => 'activeUsers']]] + $desc(self::SHARE_LIST_LIMIT + self::SHARE_LIST_HEADROOM) + $withTotal,
            ['dateRanges' => [$range], 'dimensions' => [['name' => 'brandingInterest']], 'metrics' => [['name' => 'activeUsers']]] + $desc(10),
        ]]);
        $reports2 = $batch2->json('reports');

        if ($batch2->failed() || ! is_array($reports2)) {
            return null;
        }

        [$totalsReport, $platformReport, $ageReport, $genderReport, $countryReport] = $reports1;
        [$cityReport, $regionReport, $interestReport] = $reports2;

        // totals report has one row per named date range (dimensionValues[0] = "current"/"previous")
        $totalsByPeriod = [];
        foreach ($totalsReport['rows'] ?? [] as $row) {
            $period = $row['dimensionValues'][0]['value'] ?? null;
            $vals = $row['metricValues'] ?? [];
            $totalsByPeriod[$period] = [
                'events' => isset($vals[0]['value']) ? (int) $vals[0]['value'] : null,
                'activeUsers' => isset($vals[1]['value']) ? (int) $vals[1]['value'] : null,
            ];
        }

        return [
            'events' => $totalsByPeriod['current']['events'] ?? null,
            'activeUsers' => $totalsByPeriod['current']['activeUsers'] ?? null,
            'eventsPrev' => $totalsByPeriod['previous']['events'] ?? null,
            'activeUsersPrev' => $totalsByPeriod['previous']['activeUsers'] ?? null,
            'prevRange' => $prev,
            'platform' => $this->rows($platformReport),
            'age' => $this->rows($ageReport, true),
            'gender' => $this->rows($genderReport, true),
            'country' => $this->rows($countryReport, true),
            'cityShares' => $this->shares($cityReport),
            'regionShares' => $this->shares($regionReport),
            'interests' => $this->rows($interestReport),
        ];
    }

    /**
     * Live "right now" active users + top countries via the Realtime API.
     * No date range param — GA4's realtime window is fixed at roughly the
     * last 30 minutes, so this can't be cached like the historical data.
     */
    public function realtime(): ?array
    {
        $propertyId = (string) config('team_app_stats.ga4_property_id');
        $url = self::BASE_URL.$propertyId.':runRealtimeReport';

        $response = $this->call($url, [
            'metrics' => [['name' => 'activeUsers']],
            'dimensions' => [['name' => 'country']],
        ]);
        $data = $response->json();

        if ($response->failed() || ! is_array($data)) {
            return null;
        }

        // total comes from ALL rows (including any "(not set)") so it
        // matches the true live count; the displayed list then drops that
        // noise row.
        $allCountries = $this->rows($data);
        $total = array_sum(array_column($allCountries, 'value'));
        $countries = array_values(array_filter(
            $allCountries,
            fn ($c) => ! in_array($c['name'], ['unknown', '(not set)'], true)
        ));

        return ['activeUsers' => $total, 'countries' => $countries];
    }

    /**
     * activeUsers/sessions/engagedSessions/keyEvents for [from, to] (plus the
     * previous period, for comparison) from the main biosteel.com website's
     * GA4 property (config('team_app_stats.ga4_sponsor_property_id')),
     * filtered to just the one session campaign the QR code on
     * team-sponsorship packaging uses (ga4_sponsor_campaign) — never the
     * whole site.
     */
    public function sponsorData(string $from, string $to): ?array
    {
        $propertyId = (string) config('team_app_stats.ga4_sponsor_property_id');
        $campaign = (string) config('team_app_stats.ga4_sponsor_campaign');
        $range = ['startDate' => $from, 'endDate' => $to, 'name' => 'current'];
        $prev = $this->previousPeriod($from, $to);
        $prevRange = ['startDate' => $prev['from'], 'endDate' => $prev['to'], 'name' => 'previous'];
        $base = self::BASE_URL.$propertyId;

        $response = $this->call("{$base}:runReport", [
            'dateRanges' => [$range, $prevRange],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'engagedSessions'],
                ['name' => 'keyEvents'],
            ],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'sessionCampaignName',
                    'stringFilter' => ['matchType' => 'EXACT', 'value' => $campaign],
                ],
            ],
        ]);

        if ($response->failed()) {
            return null;
        }

        // rows are keyed by the dateRange pseudo-dimension ("current"/"previous")
        $zero = ['activeUsers' => 0, 'sessions' => 0, 'engagedSessions' => 0, 'keyEvents' => 0];
        $byPeriod = [];
        foreach ($response->json('rows') ?? [] as $row) {
            $period = $row['dimensionValues'][0]['value'] ?? null;
            $vals = $row['metricValues'] ?? [];
            $byPeriod[$period] = [
                'activeUsers' => isset($vals[0]['value']) ? (int) $vals[0]['value'] : 0,
                'sessions' => isset($vals[1]['value']) ? (int) $vals[1]['value'] : 0,
                'engagedSessions' => isset($vals[2]['value']) ? (int) $vals[2]['value'] : 0,
                'keyEvents' => isset($vals[3]['value']) ? (int) $vals[3]['value'] : 0,
            ];
        }
        $cur = $byPeriod['current'] ?? $zero;
        $prv = $byPeriod['previous'] ?? $zero;

        return [
            'activeUsers' => $cur['activeUsers'],
            'sessions' => $cur['sessions'],
            'engagedSessions' => $cur['engagedSessions'],
            'keyEvents' => $cur['keyEvents'],
            'activeUsersPrev' => $prv['activeUsers'],
            'sessionsPrev' => $prv['sessions'],
            'engagedSessionsPrev' => $prv['engagedSessions'],
            'keyEventsPrev' => $prv['keyEvents'],
            'prevRange' => $prev,
        ];
    }

    /**
     * The period immediately before [from, to], same length, back-to-back
     * (e.g. Jun 11-Jul 8 -> May 14-Jun 10), for period-over-period comparison.
     */
    private function previousPeriod(string $from, string $to): array
    {
        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $days = $fromDt->diffInDays($toDt) + 1;

        $prevTo = $fromDt->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        return ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()];
    }

    /**
     * Turns a single-dimension GA4 report into a flat [{name, value}, ...]
     * list. "unknown"/"(not set)" rows are dropped when $dropUnknown is
     * true, matching how GA4's own UI hides them from age/gender/city
     * breakdowns.
     */
    private function rows(array $report, bool $dropUnknown = false): array
    {
        $out = [];
        foreach ($report['rows'] ?? [] as $row) {
            $name = $row['dimensionValues'][0]['value'] ?? '';
            $value = (int) ($row['metricValues'][0]['value'] ?? 0);

            if ($dropUnknown && in_array($name, ['unknown', '(not set)'], true)) {
                continue;
            }

            $out[] = ['name' => $name, 'value' => $value];
        }

        return $out;
    }

    /**
     * Turns a two-dimension (place, parent place) report requested with
     * metricAggregations TOTAL into [{name, detail, pct}, ...] — percentage
     * share only, raw user counts deliberately never leave the server for
     * these lists. The denominator is GA4's own report total (unique active
     * users across every row, "(not set)" included), so a share reads as
     * "% of all app users in range"; the "(not set)" place itself is then
     * dropped from the list. Falls back to the sum of the returned rows if
     * GA4 omits the total.
     */
    private function shares(array $report): array
    {
        $total = (int) ($report['totals'][0]['metricValues'][0]['value'] ?? 0);

        if ($total <= 0) {
            $total = array_sum(array_map(fn ($row) => (int) ($row['metricValues'][0]['value'] ?? 0), $report['rows'] ?? []));
        }

        if ($total <= 0) {
            return [];
        }

        $unknown = ['', 'unknown', '(not set)'];
        $out = [];

        foreach ($report['rows'] ?? [] as $row) {
            $name = $row['dimensionValues'][0]['value'] ?? '';
            $detail = $row['dimensionValues'][1]['value'] ?? '';

            if (in_array($name, $unknown, true)) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'detail' => in_array($detail, $unknown, true) ? null : $detail,
                'pct' => round((int) ($row['metricValues'][0]['value'] ?? 0) / $total * 100, 2),
            ];
        }

        return array_slice($out, 0, self::SHARE_LIST_LIMIT);
    }

    private function call(string $url, array $jsonBody): Response
    {
        return Http::withToken($this->auth->token(self::SCOPE))->post($url, $jsonBody);
    }
}
