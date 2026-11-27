<?php

namespace Tests\Unit\Services;

use App\Services\TeamAppStats\AdManagerClient;
use App\Services\TeamAppStats\GoogleServiceAccountAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdManagerClientTest extends TestCase
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
            'team_app_stats.gam_report_name' => 'networks/123/reports/456',
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

    public function test_fetch_yesterday_stats_runs_polls_and_parses_the_result_row(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'admanager.googleapis.com/v1/networks/123/reports/456:run' => Http::response(['name' => 'operations/op-1']),
            'admanager.googleapis.com/v1/operations/op-1' => Http::response([
                'done' => true,
                'response' => ['reportResult' => 'networks/123/reports/456/results/r-1'],
            ]),
            'admanager.googleapis.com/v1/networks/123/reports/456/results/r-1:fetchRows' => Http::response([
                'rows' => [[
                    'dimensionValues' => [['intValue' => '20260901']],
                    'metricValueGroups' => [[
                        'primaryValues' => [
                            ['intValue' => '1000'], ['intValue' => '25'], ['intValue' => '1200'], ['intValue' => '900'], ['intValue' => '950'],
                        ],
                    ]],
                ]],
            ]),
        ]);

        $stats = (new AdManagerClient(new GoogleServiceAccountAuth))->fetchYesterdayStats();

        $this->assertSame([
            'date' => '2026-09-01',
            'impressions' => 1000,
            'clicks' => 25,
            'ad_requests' => 1200,
            'viewable_impressions' => 900,
            'measurable_impressions' => 950,
        ], $stats);
    }

    public function test_returns_null_when_the_run_call_fails(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'admanager.googleapis.com/v1/networks/123/reports/456:run' => Http::response('error', 500),
        ]);

        $this->assertNull((new AdManagerClient(new GoogleServiceAccountAuth))->fetchYesterdayStats());
    }

    public function test_returns_null_when_polling_never_reports_done(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'admanager.googleapis.com/v1/networks/123/reports/456:run' => Http::response(['name' => 'operations/op-1']),
            'admanager.googleapis.com/v1/operations/op-1' => Http::response(['done' => false]),
        ]);

        // 2 attempts / 0s delay — this test only cares that "never done"
        // gives up and returns null, not about the real production
        // pace (15 attempts, 2s apart), which would make this test take
        // up to 30 real seconds.
        $stats = (new AdManagerClient(new GoogleServiceAccountAuth, pollAttempts: 2, pollDelaySeconds: 0))->fetchYesterdayStats();

        $this->assertNull($stats);
    }
}
