<?php

/*
 * Ported from the old app's config/combined_shipping.php: only the shared
 * part the ASP Tracker uses (plan section 5.6, "Shared code"). The rest of
 * the file (thresholds, keep rule, group limits) belonged to the Combined
 * Shipping Refund tool, which moves to the customer service app instead of
 * this one (decision 2026-12-07).
 */
return [
    // One Shopify store per brand: its domain and Admin API token, used by
    // App\Services\CombinedShipping\ShopifyGraphQlClient.
    'brands' => [
        'BS' => [
            'name' => 'BioSteel',
            'store' => env('SHOPIFY_STORE'),
            'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
        ],
        'CP' => [
            'name' => 'Canadian Protein',
            'store' => env('SHOPIFY_CP_STORE'),
            'access_token' => env('SHOPIFY_CP_ACCESS_TOKEN'),
        ],
    ],

    // Pinned, not "latest": confirmed live against the BioSteel store
    // (2026-09-15) in the old app.
    'api_version' => '2026-07',
];
