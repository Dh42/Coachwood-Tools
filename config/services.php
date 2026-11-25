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

];
