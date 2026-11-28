<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Amazon Selling Partner API. Ported from the old app's config/services.php
    // unchanged, plus vendor_codes (decision D9: Z3091 BioSteel and GQ8J9
    // Canadian Protein, both under the same Vendor Central authorization).
    // Google sign-in, the only way in (decision 2026-11-27). Which accounts
    // may sign in is the google_workspace_domains table, not an env value.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // BioSteel Teams app API (App Stats, Team Sponsorship). Read-only:
    // login, teams, counts, leaderboards.
    'biosteel_teams' => [
        'base_url' => env('BIOSTEEL_TEAMS_BASE_URL', 'https://api.biosteelteams.com'),
        'username' => env('BIOSTEEL_TEAMS_USERNAME'),
        'password' => env('BIOSTEEL_TEAMS_PASSWORD'),
    ],

    // BioSteel's Shopify store, REST Admin API (Team Sponsorship: stock
    // reads, and the draft order and invoice email, which go through
    // App\Support\ExternalWrites).
    'shopify' => [
        'store' => env('SHOPIFY_STORE'),
        'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
    ],

    // BioSteel App Stats' Google Ad Manager + GA4 integrations
    // (App\Services\TeamAppStats\GoogleServiceAccountAuth). key_file is an
    // absolute path to the service-account JSON key, outside public/.
    'google_team_app_stats' => [
        'key_file' => env('GOOGLE_TEAM_APP_STATS_KEY_FILE'),
    ],

    'sp_api' => [
        'base_url' => env('SP_API_BASE_URL', 'https://sellingpartnerapi-na.amazon.com'),
        'client_id' => env('SP_API_CLIENT_ID'),
        'client_secret' => env('SP_API_CLIENT_SECRET'),
        'vendor_refresh_token' => env('SP_API_VENDOR_REFRESH_TOKEN'),
        'vendor_code' => env('SP_API_VENDOR_CODE'),
        'vendor_codes' => array_values(array_filter(array_map('trim', explode(',', (string) env('SP_API_VENDOR_CODES', ''))))),
        'vendor_marketplace_id' => env('SP_API_VENDOR_MARKETPLACE_ID', 'A2EUQ1WTGCTBG2'), // amazon.ca
        'seller_refresh_token' => env('SP_API_SELLER_REFRESH_TOKEN'),
        'seller_id' => env('SP_API_SELLER_ID'),
        'seller_marketplace_id' => env('SP_API_SELLER_MARKETPLACE_ID', 'ATVPDKIKX0DER'), // amazon.com (US)
    ],

    // CDQ's consistency check (cdq:consistency). Ported from the old app.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'model' => env('ANTHROPIC_CDQ_MODEL', 'claude-haiku-4-5-20251001'),
    ],

];
