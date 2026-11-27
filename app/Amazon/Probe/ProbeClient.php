<?php

namespace App\Amazon\Probe;

use App\Services\Amazon\SpApiClient;
use LogicException;

/**
 * The shared SP-API client, restricted to reads. Every GET is allowed. The
 * only POSTs allowed create a report or a Data Kiosk query, which read data
 * and change nothing on the account. Anything else (for example the
 * inherited patchListing()) throws before a request is sent.
 */
class ProbeClient extends SpApiClient
{
    private const ALLOWED_POST_PATHS = [
        '/reports/2021-06-30/reports',
        '/dataKiosk/2023-11-15/queries',
    ];

    /**
     * @return array{status: int, body: ?array}
     */
    public function call(string $method, string $path, array $query = [], ?array $jsonBody = null, ?string $refreshToken = null): array
    {
        return $this->request($method, $path, $query, $jsonBody, $refreshToken);
    }

    protected function request(string $method, string $path, array $query = [], ?array $jsonBody = null, ?string $refreshToken = null): array
    {
        $method = strtoupper($method);
        $allowed = $method === 'GET' || ($method === 'POST' && in_array($path, self::ALLOWED_POST_PATHS, true));

        if (! $allowed) {
            throw new LogicException("This SP-API client is read-only; refused {$method} {$path}.");
        }

        return parent::request($method, $path, $query, $jsonBody, $refreshToken);
    }
}
