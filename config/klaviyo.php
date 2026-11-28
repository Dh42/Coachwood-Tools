<?php

return [

    /*
    | Two genuinely separate Klaviyo accounts, one per brand — same "brand
    | tag" keys ('BS'/'CP') as config/combined_shipping.php, so a caller
    | that already knows which Shopify store a refund came from can look up
    | the matching Klaviyo account with no extra mapping. Private API Keys
    | (Klaviyo Settings > API Keys > Private API Keys, "Full Access" or at
    | minimum "Events: Write" + "Profiles: Write" scope), never the public
    | site ID. Blank until set — KlaviyoClient refuses to send for a brand
    | whose key is blank rather than attempting a doomed request.
    */
    'accounts' => [
        'BS' => [
            'name' => 'BioSteel',
            'private_api_key' => env('KLAVIYO_BS_PRIVATE_API_KEY'),
        ],
        'CP' => [
            'name' => 'Canadian Protein',
            'private_api_key' => env('KLAVIYO_CP_PRIVATE_API_KEY'),
        ],
    ],

    // Klaviyo requires a pinned revision date on every API call. Live-
    // confirmed against both real brand accounts (2026-09-22/23) — a real
    // event sent under this revision was fetched back via the Events API
    // with the correct metric name and profile.
    'api_revision' => '2024-10-15',

    // Event (metric) names this app triggers, across every tool that sends
    // one — not scoped to any one tool. Klaviyo creates the metric the
    // first time an event with this name is received — get these right
    // before relying on them as a flow trigger, since renaming later means
    // updating the flow trigger too, not just this config. Both
    // single_order_refunded/multiple_orders_refunded and
    // claimed_sponsorship_package are live-confirmed (2026-09-22/23,
    // BioSteel; the first two also on Canadian Protein).
    'events' => [
        // The old app's Combined Shipping Refund tool, which moves to the
        // customer service app (decision 2026-12-07); unused here. Brand:
        // whichever store the batch was for.
        'single_order_refunded' => 'Shipping Refunded - Single Order',
        'multiple_orders_refunded' => 'Shipping Refunded - Multiple Orders',

        // Team Sponsorship tool — brand: always 'BS', BioSteel-only.
        'claimed_sponsorship_package' => 'Claimed Sponsorship Package',
    ],

];
