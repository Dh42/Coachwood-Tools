<?php

return [

    /*
    | Two genuinely separate API programs, not one client with a market
    | flag — Walmart's own docs say US and Global (CA/MX/CL) integrations
    | are separate, with separate onboarding and separate credentials. See
    | app/Services/WalmartUs/ (built) and app/Services/WalmartCa/ (not yet
    | built — Phase 3).
    */

    'us' => [
        'base_url' => env('WALMART_US_BASE_URL', 'https://marketplace.walmartapis.com/v3'),
        'client_id' => env('WALMART_US_CLIENT_ID'),
        'client_secret' => env('WALMART_US_CLIENT_SECRET'),
        // 'sandbox' or 'production' — purely descriptive right now (which
        // credential set is loaded already determines which environment is
        // actually hit); kept as an explicit value rather than inferred so
        // logs/error messages can say which one is active.
        'environment' => env('WALMART_US_ENVIRONMENT', 'sandbox'),
        // Rate-limit backoff — read from config, not hardcoded, per the
        // explicit decision not to repeat SpApiClient's fixed-sleep pacing.
        // Walmart's own proprietary rate-limit headers aren't confirmed yet
        // (docs are gated behind partner login) — these bound the *fallback*
        // exponential backoff, used whenever a specific Retry-After isn't
        // present on a 429.
        'max_retries' => (int) env('WALMART_US_MAX_RETRIES', 5),
        'base_backoff_ms' => (int) env('WALMART_US_BASE_BACKOFF_MS', 500),
        'max_backoff_ms' => (int) env('WALMART_US_MAX_BACKOFF_MS', 30000),
    ],

    // Same shape, unused until Phase 3 (Canada) — added now so this file's
    // final shape is visible early. Nothing reads these keys yet.
    'ca' => [
        'base_url' => env('WALMART_CA_BASE_URL', 'https://marketplace.walmartapis.com/v3'),
        'client_id' => env('WALMART_CA_CLIENT_ID'),
        'client_secret' => env('WALMART_CA_CLIENT_SECRET'),
        'environment' => env('WALMART_CA_ENVIRONMENT', 'sandbox'),
        'max_retries' => (int) env('WALMART_CA_MAX_RETRIES', 5),
        'base_backoff_ms' => (int) env('WALMART_CA_BASE_BACKOFF_MS', 500),
        'max_backoff_ms' => (int) env('WALMART_CA_MAX_BACKOFF_MS', 30000),
    ],

];
