<?php

namespace App\Services\Klaviyo;

use App\Support\ExternalWrites;
use App\Support\ExternalWritesDisabled;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper around Klaviyo's Events API (POST /api/events/) for one
 * brand's own Klaviyo account — shared across every tool that sends a
 * Klaviyo event, not owned by any one of them (originally built for, and
 * still used by, the Combined Shipping Refund tool's KlaviyoRefundNotifier;
 * Team Sponsorship's "Claimed Sponsorship Package" event is a second,
 * independent caller). Live-confirmed against both real brand accounts
 * (2026-09-22/23, BioSteel and Canadian Protein) — a real event sent with
 * this class was fetched back via the Events API afterward with the
 * correct metric name and profile, not just trusted from a 202 response.
 *
 * Deliberately never throws for anything past config() — a Klaviyo outage
 * or bad credential must never fail the real action (a refund, an order)
 * it's reporting on. Callers get back a plain success/failure result to
 * log, not an exception to catch. While external writes are off, nothing is
 * sent and the result says so, in the same shape.
 */
class KlaviyoClient
{
    public function __construct(protected string $brand) {}

    /** @return array{status: ?int, success: bool, errors: ?array} */
    public function sendEvent(string $metricName, string $email, array $properties = [], ?string $uniqueId = null): array
    {
        $config = $this->config();
        if (! ExternalWrites::allowed()) {
            return ['status' => null, 'success' => false, 'errors' => [(new ExternalWritesDisabled("Klaviyo event \"{$metricName}\""))->getMessage()]];
        }
        if ($config === null) {
            return ['status' => null, 'success' => false, 'errors' => ["Klaviyo isn't configured for brand {$this->brand} — private_api_key is blank."]];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Klaviyo-API-Key '.$config['private_api_key'],
            'revision' => config('klaviyo.api_revision'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(15)->post('https://a.klaviyo.com/api/events/', [
            'data' => [
                'type' => 'event',
                'attributes' => array_filter([
                    'properties' => $properties,
                    'unique_id' => $uniqueId,
                    'time' => now()->toIso8601String(),
                    'metric' => [
                        'data' => [
                            'type' => 'metric',
                            'attributes' => ['name' => $metricName],
                        ],
                    ],
                    'profile' => [
                        'data' => [
                            'type' => 'profile',
                            'attributes' => ['email' => $email],
                        ],
                    ],
                ], fn ($v) => $v !== null),
            ],
        ]);

        $success = $response->successful();
        $errors = $success ? null : ($response->json('errors') ?? [$response->body()]);

        Log::info('Klaviyo event sent', [
            'brand' => $this->brand,
            'metric' => $metricName,
            'status' => $response->status(),
            'success' => $success,
        ]);

        if (! $success) {
            Log::warning('Klaviyo event failed', [
                'brand' => $this->brand,
                'metric' => $metricName,
                'status' => $response->status(),
                'errors' => $errors,
            ]);
        }

        return ['status' => $response->status(), 'success' => $success, 'errors' => $errors];
    }

    /** @return ?array{name: string, private_api_key: string} null when unconfigured for this brand, rather than throwing — see class docblock. */
    protected function config(): ?array
    {
        $config = config("klaviyo.accounts.{$this->brand}");

        if ($config === null) {
            throw new RuntimeException("Unknown Klaviyo brand: {$this->brand}");
        }

        if (blank($config['private_api_key'] ?? null)) {
            return null;
        }

        return $config;
    }
}
