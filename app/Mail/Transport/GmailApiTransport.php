<?php

namespace App\Mail\Transport;

use App\Models\GmailConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends mail through the Gmail API as the account connected in Admin > Mail
 * settings. Ported from the old app, with one fix (found issue 19): if
 * Google rejects the cached access token (401, e.g. access was revoked or
 * the account reconnected elsewhere), the token is dropped and the send is
 * tried once more with a fresh one, instead of failing for up to 55 minutes.
 */
class GmailApiTransport extends AbstractTransport
{
    private const TOKEN_CACHE_KEY = 'gmail_api_access_token';

    protected function doSend(SentMessage $message): void
    {
        $raw = rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '=');

        $response = $this->post($raw);

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->post($raw);
        }

        if ($response->failed()) {
            throw new RuntimeException('Gmail API send failed: '.$response->body());
        }
    }

    private function post(string $raw): Response
    {
        return Http::withToken($this->accessToken())
            ->post('https://www.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $raw,
            ]);
    }

    protected function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $connection = GmailConnection::current();

            if (! $connection) {
                throw new RuntimeException(
                    'Gmail sending account not connected. Visit Admin > Mail Settings to connect it.'
                );
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if ($response->failed()) {
                throw new RuntimeException('Failed to refresh Gmail access token: '.$response->body());
            }

            return $response->json('access_token');
        });
    }

    public function __toString(): string
    {
        return 'gmail+api';
    }
}
