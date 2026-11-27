<?php

namespace Tests\Unit\Services;

use App\Services\TeamAppStats\Ga4Client;
use App\Services\TeamAppStats\GoogleServiceAccountAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Ga4ClientTest extends TestCase
{
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);

        $this->keyFile = tempnam(sys_get_temp_dir(), 'gsa').'.json';
        file_put_contents($this->keyFile, json_encode([
            'private_key' => $privateKey,
            'client_email' => 'test@example.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.google_team_app_stats.key_file' => $this->keyFile,
            'team_app_stats.ga4_property_id' => '111',
            'team_app_stats.ga4_sponsor_property_id' => '222',
            'team_app_stats.ga4_sponsor_campaign' => 'TestCampaign',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);

        parent::tearDown();
    }

    public function test_dashboard_data_parses_totals_and_breakdowns_from_both_batches(): void
    {
        $totalsReport = ['rows' => [
            ['dimensionValues' => [['value' => 'current']], 'metricValues' => [['value' => '500'], ['value' => '80']]],
            ['dimensionValues' => [['value' => 'previous']], 'metricValues' => [['value' => '400'], ['value' => '60']]],
        ]];
        $platformReport = ['rows' => [['dimensionValues' => [['value' => 'iOS']], 'metricValues' => [['value' => '50']]]]];
        $ageReport = ['rows' => [
            ['dimensionValues' => [['value' => '25-34']], 'metricValues' => [['value' => '30']]],
            ['dimensionValues' => [['value' => 'unknown']], 'metricValues' => [['value' => '5']]],
        ]];
        $genderReport = ['rows' => []];
        $countryReport = ['rows' => [['dimensionValues' => [['value' => 'Canada']], 'metricValues' => [['value' => '70']]]]];
        $cityReport = [
            'rows' => [
                ['dimensionValues' => [['value' => 'Windsor'], ['value' => 'Ontario']], 'metricValues' => [['value' => '20']]],
                ['dimensionValues' => [['value' => '(not set)'], ['value' => 'Ontario']], 'metricValues' => [['value' => '10']]],
                ['dimensionValues' => [['value' => 'Chicago'], ['value' => 'Illinois']], 'metricValues' => [['value' => '16']]],
            ],
            'totals' => [['dimensionValues' => [['value' => 'RESERVED_TOTAL']], 'metricValues' => [['value' => '200']]]],
        ];
        $regionReport = [
            'rows' => [
                ['dimensionValues' => [['value' => 'Ontario'], ['value' => 'Canada']], 'metricValues' => [['value' => '150']]],
                ['dimensionValues' => [['value' => 'Illinois'], ['value' => '(not set)']], 'metricValues' => [['value' => '1']]],
            ],
            'totals' => [['dimensionValues' => [['value' => 'RESERVED_TOTAL']], 'metricValues' => [['value' => '200']]]],
        ];
        $interestReport = ['rows' => [['dimensionValues' => [['value' => 'Sports Fans']], 'metricValues' => [['value' => '20']]]]];

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'analyticsdata.googleapis.com/v1beta/properties/111:batchRunReports' => Http::sequence()
                ->push(['reports' => [$totalsReport, $platformReport, $ageReport, $genderReport, $countryReport]])
                ->push(['reports' => [$cityReport, $regionReport, $interestReport]]),
        ]);

        $data = (new Ga4Client(new GoogleServiceAccountAuth))->dashboardData('2026-08-01', '2026-08-31');

        $this->assertSame(500, $data['events']);
        $this->assertSame(80, $data['activeUsers']);
        $this->assertSame(400, $data['eventsPrev']);
        $this->assertSame(60, $data['activeUsersPrev']);
        $this->assertSame([['name' => 'iOS', 'value' => 50]], $data['platform']);
        // "unknown" is dropped from age (dropUnknown = true)
        $this->assertSame([['name' => '25-34', 'value' => 30]], $data['age']);
        $this->assertSame([['name' => 'Canada', 'value' => 70]], $data['country']);
        // shares are out of GA4's report total (200), "(not set)" cities
        // dropped from the list but still counted in that total
        $this->assertSame([
            ['name' => 'Windsor', 'detail' => 'Ontario', 'pct' => 10.0],
            ['name' => 'Chicago', 'detail' => 'Illinois', 'pct' => 8.0],
        ], $data['cityShares']);
        $this->assertSame([
            ['name' => 'Ontario', 'detail' => 'Canada', 'pct' => 75.0],
            ['name' => 'Illinois', 'detail' => null, 'pct' => 0.5],
        ], $data['regionShares']);
        $this->assertArrayNotHasKey('city', $data);
        $this->assertSame([['name' => 'Sports Fans', 'value' => 20]], $data['interests']);

        Http::assertSent(function ($request) {
            $cityRequest = $request['requests'][0] ?? [];

            return str_ends_with($request->url(), ':batchRunReports')
                && ($cityRequest['dimensions'] ?? null) === [['name' => 'city'], ['name' => 'region']]
                && ($cityRequest['limit'] ?? null) >= 100
                && ($cityRequest['metricAggregations'] ?? null) === ['TOTAL'];
        });
    }

    public function test_share_lists_are_capped_at_100_and_fall_back_to_the_row_sum_without_a_total(): void
    {
        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = ['dimensionValues' => [['value' => "City {$i}"], ['value' => 'Ontario']], 'metricValues' => [['value' => '1']]];
        }
        $empty = ['rows' => []];

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'analyticsdata.googleapis.com/v1beta/properties/111:batchRunReports' => Http::sequence()
                ->push(['reports' => [$empty, $empty, $empty, $empty, $empty]])
                ->push(['reports' => [['rows' => $rows], $empty, $empty]]),
        ]);

        $data = (new Ga4Client(new GoogleServiceAccountAuth))->dashboardData('2026-08-01', '2026-08-31');

        $this->assertCount(100, $data['cityShares']);
        $this->assertSame(0.83, $data['cityShares'][0]['pct']); // 1 of 120
        $this->assertSame([], $data['regionShares']);
    }

    public function test_dashboard_data_returns_null_when_the_first_batch_fails(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'analyticsdata.googleapis.com/v1beta/properties/111:batchRunReports' => Http::response('error', 500),
        ]);

        $this->assertNull((new Ga4Client(new GoogleServiceAccountAuth))->dashboardData('2026-08-01', '2026-08-31'));
    }

    public function test_realtime_sums_all_countries_but_lists_only_known_ones(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'analyticsdata.googleapis.com/v1beta/properties/111:runRealtimeReport' => Http::response(['rows' => [
                ['dimensionValues' => [['value' => 'Canada']], 'metricValues' => [['value' => '5']]],
                ['dimensionValues' => [['value' => '(not set)']], 'metricValues' => [['value' => '2']]],
            ]]),
        ]);

        $data = (new Ga4Client(new GoogleServiceAccountAuth))->realtime();

        $this->assertSame(7, $data['activeUsers']);
        $this->assertSame([['name' => 'Canada', 'value' => 5]], $data['countries']);
    }

    public function test_sponsor_data_filters_by_the_configured_campaign_and_computes_previous_period(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'analyticsdata.googleapis.com/v1beta/properties/222:runReport' => Http::response(['rows' => [
                ['dimensionValues' => [['value' => 'current']], 'metricValues' => [['value' => '10'], ['value' => '12'], ['value' => '8'], ['value' => '3']]],
                ['dimensionValues' => [['value' => 'previous']], 'metricValues' => [['value' => '6'], ['value' => '7'], ['value' => '4'], ['value' => '1']]],
            ]]),
        ]);

        $data = (new Ga4Client(new GoogleServiceAccountAuth))->sponsorData('2026-08-08', '2026-08-14');

        $this->assertSame(10, $data['activeUsers']);
        $this->assertSame(12, $data['sessions']);
        $this->assertSame(8, $data['engagedSessions']);
        $this->assertSame(3, $data['keyEvents']);
        $this->assertSame(6, $data['activeUsersPrev']);
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-07'], $data['prevRange']);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'properties/222:runReport')
                && ($request['dimensionFilter']['filter']['stringFilter']['value'] ?? null) === 'TestCampaign';
        });
    }
}
