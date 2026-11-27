<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Retry rules for outbound HTTP calls that hit a transient network blip.
 *
 * Only connect-phase failures are retried: the request never reached the
 * server, so retrying is safe even for a write (e.g. a Shopify refundCreate
 * mutation). A read timeout ("Operation timed out ... bytes received") is
 * deliberately NOT retried here, since the server may already have acted
 * on the request.
 */
class HttpRetry
{
    /** Waits between attempts, in ms: 3 attempts in total. */
    public const CONNECT_BACKOFF_MS = [1000, 3000];

    private const CONNECT_FAILURE_PATTERNS = [
        'Failed to connect to',
        'Could not resolve host',
        'Resolving timed out',
        'Connection timed out after',
        'Connection refused',
    ];

    public static function isConnectFailure(Throwable $e): bool
    {
        if (! $e instanceof ConnectionException) {
            return false;
        }

        foreach (self::CONNECT_FAILURE_PATTERNS as $pattern) {
            if (str_contains($e->getMessage(), $pattern)) {
                return true;
            }
        }

        return false;
    }
}
