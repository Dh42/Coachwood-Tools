<?php

namespace App\Services\Anthropic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Anthropic Messages API — extracted once a second
 * caller (VcBirdseyeAiSummaryService, alongside CdqConsistencyService)
 * needed the exact same auth/timeout/request-shape boilerplate. Returns
 * the decoded response envelope, or null on any failure (missing key,
 * network error, non-2xx) — callers decide what "no result" means for
 * their own feature (CDQ: no deduction; Birdseye summary: keep the
 * previous one), this client only ever tells them whether it got one.
 */
class AnthropicClient
{
    /** Why the last sendMessage() returned null (e.g. "HTTP 529 overloaded_error: Overloaded"), for callers to surface — null after a success. */
    public ?string $lastError = null;

    public function __construct(private readonly ?string $model = null) {}

    /** @return array|null decoded response envelope, or null if the key is missing, the call fails, or the response isn't 2xx */
    public function sendMessage(string $system, string $userContent, int $maxTokens = 1024): ?array
    {
        $this->lastError = null;

        $apiKey = config('services.anthropic.api_key');
        if (empty($apiKey)) {
            Log::warning('AnthropicClient: ANTHROPIC_API_KEY not configured, skipping live call');
            $this->lastError = 'ANTHROPIC_API_KEY not configured';

            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => config('services.anthropic.version'),
            ])->timeout(60)->post(config('services.anthropic.base_url').'/v1/messages', [
                'model' => $this->model ?? config('services.anthropic.model'),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $userContent]],
            ]);
        } catch (ConnectionException $e) {
            // A timeout/network drop is "no result" like any other failure —
            // not an exception that aborts the caller's whole batch.
            $this->lastError = 'connection failed: '.$e->getMessage();
            Log::warning('AnthropicClient: '.$this->lastError);

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = trim(sprintf(
                'HTTP %d %s: %s',
                $response->status(),
                (string) $response->json('error.type'),
                mb_strimwidth((string) ($response->json('error.message') ?? $response->body()), 0, 200, '…')
            ));
            Log::warning('AnthropicClient: '.$this->lastError);

            return null;
        }

        return $response->json();
    }

    /** Pulls the plain text out of a successful response envelope's first content block, or null if it's not the expected shape. */
    public static function extractText(array $response): ?string
    {
        $text = $response['content'][0]['text'] ?? null;

        return is_string($text) ? $text : null;
    }
}
