<?php

namespace Tests\Feature\Console;

use App\Models\TeamAppAdSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamAppStatsCollectAdsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();

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

        // Deliberately NOT faking the full happy path here: Http::fake() calls
        // merge (append) rather than replace, and the first matching stub in
        // registration order wins — a default set here would silently win
        // over a later, more specific override in a failure test. Each test
        // method fakes its own complete set instead.
    }

    private function fakeHappyPath(): void
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
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);

        parent::tearDown();
    }

    public function test_collect_ads_upserts_one_row_for_the_returned_date(): void
    {
        $this->fakeHappyPath();

        $this->artisan('team-app-stats:collect-ads')->assertSuccessful();

        $row = TeamAppAdSnapshot::find('2026-09-01');
        $this->assertNotNull($row);
        $this->assertSame(1000, $row->impressions);
        $this->assertSame(25, $row->clicks);
        $this->assertSame(1200, $row->ad_requests);
    }

    public function test_rerunning_for_the_same_date_refreshes_rather_than_duplicates(): void
    {
        $this->fakeHappyPath();

        $this->artisan('team-app-stats:collect-ads')->assertSuccessful();
        $this->artisan('team-app-stats:collect-ads')->assertSuccessful();

        $this->assertSame(1, TeamAppAdSnapshot::count());
    }

    public function test_fails_gracefully_when_ad_manager_never_finishes(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'admanager.googleapis.com/v1/networks/123/reports/456:run' => Http::response('error', 500),
        ]);

        $this->artisan('team-app-stats:collect-ads')->assertFailed();

        $this->assertSame(0, TeamAppAdSnapshot::count());
    }
}
