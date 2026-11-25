<?php

namespace App\Support;

/**
 * The one place that decides whether this app may write to an outside system
 * (Shopify, Klaviyo, Amazon). Every write path calls ensureAllowed() (or checks
 * allowed() to choose its preview path) immediately before sending.
 */
final class ExternalWrites
{
    public static function allowed(): bool
    {
        return config('tools.external_writes') === true;
    }

    /**
     * @param  string  $action  Short description used in the error, e.g. "Shopify refundCreate".
     *
     * @throws ExternalWritesDisabled
     */
    public static function ensureAllowed(string $action): void
    {
        if (! self::allowed()) {
            throw new ExternalWritesDisabled($action);
        }
    }
}
