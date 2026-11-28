<?php

namespace App\Support;

use App\Cutover\Cutover;

/**
 * The one place that decides whether this app may write to an outside system
 * (Shopify, Klaviyo, Amazon). Every write path calls ensureAllowed() (or checks
 * allowed() to choose its preview path) immediately before sending.
 *
 * Writes are allowed when TOOLS_EXTERNAL_WRITES is on (everything), or for
 * one cutover group once it has moved from the old app (`cutover:move`,
 * decision 2026-12-07). A write names its group; one that names none needs
 * the global switch.
 */
final class ExternalWrites
{
    public static function allowed(?string $group = null): bool
    {
        if (config('tools.external_writes') === true) {
            return true;
        }

        return $group !== null && Cutover::hasMoved($group);
    }

    /**
     * @param  string  $action  Short description used in the error, e.g. "Shopify refundCreate".
     * @param  ?string  $group  The cutover group the write belongs to (config/cutover.php).
     *
     * @throws ExternalWritesDisabled
     */
    public static function ensureAllowed(string $action, ?string $group = null): void
    {
        if (! self::allowed($group)) {
            throw new ExternalWritesDisabled($action);
        }
    }
}
