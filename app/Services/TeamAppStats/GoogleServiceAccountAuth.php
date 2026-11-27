<?php

namespace App\Services\TeamAppStats;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * JWT-bearer token exchange for the service account shared by AdManagerClient
 * and Ga4Client — ported from the legacy standalone tool's
 * googleServiceAccountToken(), which cached one token per scope in a local
 * file (a standalone-script necessity). Here the same per-scope cache just
 * uses Laravel's Cache facade, matching this app's existing Gmail-transport
 * token-caching convention (App\Mail\Transport\GmailApiTransport).
 */
class GoogleServiceAccountAuth
{
    public function token(string $scope): string
    {
        return Cache::remember(
            'team_app_stats_google_token:'.$scope,
            now()->addMinutes(55),
            fn () => $this->requestToken($scope)
        );
    }

    private function requestToken(string $scope): string
    {
        $keyFile = (string) config('services.google_team_app_stats.key_file');

        if ($keyFile === '' || ! is_file($keyFile)) {
            throw new RuntimeException("GOOGLE_TEAM_APP_STATS_KEY_FILE is missing or not a valid path: {$keyFile}");
        }

        $key = json_decode((string) file_get_contents($keyFile), true);

        if (! isset($key['private_key'], $key['client_email'], $key['token_uri'])) {
            throw new RuntimeException('GOOGLE_TEAM_APP_STATS_KEY_FILE is not a valid service account key.');
        }

        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url(json_encode([
            'iss' => $key['client_email'],
            'scope' => $scope,
            'aud' => $key['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signingInput = "{$header}.{$claims}";

        if (! openssl_sign($signingInput, $signature, $key['private_key'], 'sha256WithRSAEncryption')) {
            throw new RuntimeException('Google JWT signing failed: '.openssl_error_string());
        }

        $jwt = $signingInput.'.'.$this->base64Url($signature);

        $response = Http::asForm()->post($key['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google token exchange failed: '.$response->body());
        }

        return $accessToken;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
