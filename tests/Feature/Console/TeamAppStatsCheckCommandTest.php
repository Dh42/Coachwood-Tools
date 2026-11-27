<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `team-app-stats:check`: says which connection step fails, without printing keys or data. */
class TeamAppStatsCheckCommandTest extends TestCase
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
            'client_email' => 'stats@example.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.google_team_app_stats.key_file' => $this->keyFile,
            'team_app_stats.ga4_property_id' => '111',
            'team_app_stats.ga4_sponsor_property_id' => '222',
            'services.biosteel_teams.base_url' => 'https://api.biosteelteams.test',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);

        parent::tearDown();
    }

    public function test_every_step_passing_says_so(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => [['metricValues' => [['value' => '123']]]]]),
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer bt']),
        ]);

        $this->artisan('team-app-stats:check')
            ->expectsOutputToContain('Every connection works.')
            ->doesntExpectOutputToContain('123')
            ->assertSuccessful();
    }

    public function test_a_missing_key_file_is_named_and_google_is_not_called(): void
    {
        config(['services.google_team_app_stats.key_file' => '/nowhere/key.json']);
        Http::fake(['*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer bt'])]);

        $this->artisan('team-app-stats:check')
            ->expectsOutputToContain('No file at /nowhere/key.json')
            ->assertFailed();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'googleapis.com'));
    }

    public function test_a_property_the_service_account_cannot_read_shows_googles_reason(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'analyticsdata.googleapis.com/v1beta/properties/111:runReport' => Http::response(['rows' => []]),
            'analyticsdata.googleapis.com/v1beta/properties/222:runReport' => Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'User does not have sufficient permissions for this property.']], 403),
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer bt']),
        ]);

        $this->artisan('team-app-stats:check')
            ->expectsOutputToContain('PERMISSION_DENIED')
            ->expectsOutputToContain('1 check(s) failed.')
            ->assertFailed();
    }

    public function test_it_never_prints_the_private_key_or_tokens(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'secret-access-token']),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => []]),
            '*/api/users/login' => Http::response([], 200, ['Authorization' => 'Bearer secret-bt-token']),
        ]);

        $this->artisan('team-app-stats:check')
            ->doesntExpectOutputToContain('PRIVATE KEY')
            ->doesntExpectOutputToContain('secret-access-token')
            ->doesntExpectOutputToContain('secret-bt-token')
            ->assertSuccessful();
    }
}
