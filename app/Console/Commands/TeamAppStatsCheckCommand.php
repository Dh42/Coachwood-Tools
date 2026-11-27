<?php

namespace App\Console\Commands;

use App\Services\BioSteelTeams\BioSteelTeamsClient;
use App\Services\TeamAppStats\GoogleServiceAccountAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks BioSteel App Stats' outside connections one step at a time and
 * says which step fails: the service-account key file, Google's token
 * exchange, each GA4 property and Ad Manager, and the BioSteel Teams login.
 * Lesley runs it on the server. It prints no keys, tokens or data: only
 * whether each step worked, and Google's own error status when it didn't.
 */
class TeamAppStatsCheckCommand extends Command
{
    protected $signature = 'team-app-stats:check';

    protected $description = "Check BioSteel App Stats' connections to Google (GA4, Ad Manager) and the BioSteel Teams API";

    private const GA4_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    private const ADS_SCOPE = 'https://www.googleapis.com/auth/admanager.readonly';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(GoogleServiceAccountAuth $auth, BioSteelTeamsClient $teams): int
    {
        $keyOk = $this->checkKeyFile();

        if ($keyOk) {
            Cache::forget('team_app_stats_google_token:'.self::GA4_SCOPE);
            Cache::forget('team_app_stats_google_token:'.self::ADS_SCOPE);

            $ga4Token = $this->attempt('Google token (Analytics)', fn () => $auth->token(self::GA4_SCOPE));
            if ($ga4Token !== null) {
                $this->checkGa4($ga4Token, 'GA4 app property', (string) config('team_app_stats.ga4_property_id'));
                $this->checkGa4($ga4Token, 'GA4 sponsorship property', (string) config('team_app_stats.ga4_sponsor_property_id'));
            }
            $this->attempt('Google token (Ad Manager)', fn () => $auth->token(self::ADS_SCOPE));
        }

        Cache::forget('biosteel_teams_api_token');
        $this->attempt('BioSteel Teams login', fn () => $teams->token());

        $this->table(['Check', 'Result', 'Detail'], $this->rows);

        $failed = count(array_filter($this->rows, fn (array $row) => $row[1] !== 'OK'));
        if ($failed > 0) {
            $this->warn("{$failed} check(s) failed.");

            return self::FAILURE;
        }

        $this->info('Every connection works.');

        return self::SUCCESS;
    }

    private function checkKeyFile(): bool
    {
        $path = (string) config('services.google_team_app_stats.key_file');

        $problem = match (true) {
            $path === '' => 'GOOGLE_TEAM_APP_STATS_KEY_FILE is not set (run php artisan optimize after editing .env)',
            ! file_exists($path) => "No file at {$path}",
            ! is_readable($path) => "{$path} exists but the site user can't read it (check its owner and permissions)",
            default => null,
        };

        if ($problem === null) {
            $key = json_decode((string) file_get_contents($path), true);
            $problem = isset($key['private_key'], $key['client_email'], $key['token_uri'])
                ? null
                : "{$path} is not a service-account key (needs private_key, client_email and token_uri)";
        }

        $this->rows[] = $problem === null
            ? ['Key file', 'OK', 'Service account '.$key['client_email']]
            : ['Key file', 'FAILED', $problem];

        return $problem === null;
    }

    private function checkGa4(string $token, string $label, string $propertyId): void
    {
        if ($propertyId === '') {
            $this->rows[] = [$label, 'FAILED', 'No property ID in config/team_app_stats.php'];

            return;
        }

        $response = Http::withToken($token)->post("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport", [
            'dateRanges' => [['startDate' => 'yesterday', 'endDate' => 'yesterday']],
            'metrics' => [['name' => 'activeUsers']],
        ]);

        $this->rows[] = $response->successful()
            ? [$label, 'OK', "Property {$propertyId}"]
            : [$label, 'FAILED', "Property {$propertyId}: HTTP {$response->status()} ".trim($response->json('error.status').' '.$response->json('error.message'))];
    }

    private function attempt(string $label, callable $step): ?string
    {
        try {
            $result = $step();
            $this->rows[] = [$label, 'OK', ''];

            return $result;
        } catch (Throwable $e) {
            $this->rows[] = [$label, 'FAILED', $this->withoutSecrets($e->getMessage())];

            return null;
        }
    }

    /** Google's token errors carry no secrets, but trim anything long. */
    private function withoutSecrets(string $message): string
    {
        return mb_strimwidth(preg_replace('/\s+/', ' ', $message), 0, 200, '…');
    }
}
