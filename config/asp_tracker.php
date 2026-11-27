<?php

return [

    /*
    | One entry per Shopify store. Credentials aren't duplicated here — each
    | store points at a Combined Shipping brand (config/combined_shipping.php)
    | and reuses that brand's store domain + Admin API token through
    | CombinedShipping\ShopifyGraphQlClient. The CP token was confirmed live
    | (2026-10-19) to carry read_orders + read_all_orders, which is all the
    | backfill and re-fetches need. BioSteel (brand 'BS') reuses the Team
    | Sponsorship app's token (services.shopify) — confirmed live 2026-10-20:
    | read_orders + read_all_orders, CAD shop currency, taxesIncluded false,
    | America/New_York, Recharge selling plans like CP.
    |
    | Each store is its own tool (tool_slug → tools row, permission
    | tool.<slug>, page at /tools/<slug>).
    |
    | default_exclusions: orders left out of the page's default channel view
    | (still selectable in the Channel filter), by source_name or app name.
    | Baked into the daily rollups (is_excluded) — after changing it, run
    | asp:rebuild-rollups for that store.
    |
    | webhook_secret is the owning custom app's "API secret key" (Shopify
    | Admin > Settings > Apps > Develop apps > the app > API credentials) —
    | NOT the access token. Webhooks are signed with it; while it's blank,
    | every delivery is rejected (401) and asp:register-webhooks refuses to run.
    |
    | timezone is the shop's own (shop.ianaTimezone, confirmed live for CP) —
    | order_date/week_start are bucketed in it so days and months line up
    | with Shopify's own reports.
    */
    'stores' => [
        'CP' => [
            'name' => 'Canadian Protein',
            'brand' => 'CP',
            'tool_slug' => 'asp-tracker',
            'webhook_secret' => env('SHOPIFY_CP_WEBHOOK_SECRET'),
            'timezone' => 'America/New_York',
            // User decision 2026-10-19: from 2024 rather than full history
            // (1.05M orders back to 2019). Re-running asp:backfill --fresh
            // --since=<earlier date> adds older history later.
            'backfill_since' => '2024-01-01',
            'default_exclusions' => [
                'source_names' => ['shopify_draft_order'],
                'app_names' => [],
                'label' => 'All except draft orders',
            ],
        ],
        'BS' => [
            'name' => 'BioSteel',
            'brand' => 'BS',
            'tool_slug' => 'asp-tracker-bs',
            'webhook_secret' => env('SHOPIFY_BS_WEBHOOK_SECRET'),
            'timezone' => 'America/New_York',
            // 302,705 orders since 2024 (565,781 back to 2018), same cut-off as CP.
            'backfill_since' => '2024-01-01',
            // User decision 2026-10-20. In a 1,000-order sample: Influencer
            // Hero 16% (82 of 88 at $0 — gifted product), GoDataFeed 6%
            // (Amazon / Walmart marketplace orders, marketplace-priced).
            // Drafts include the $0 Sponsorship orders.
            'default_exclusions' => [
                'source_names' => ['shopify_draft_order'],
                'app_names' => ['Influencer Hero', 'GoDataFeed'],
                'label' => 'Retail (no drafts, Influencer Hero or marketplaces)',
            ],
        ],
    ],

    // Where Shopify POSTs order/refund webhooks for each store — {store} is
    // the lowercased key above. Must be publicly reachable (production).
    'webhook_callback_url' => env('APP_URL').'/webhooks/shopify/asp/{store}',

    'webhook_topics' => ['ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED', 'REFUNDS_CREATE'],

    // Codes matching a pattern here are grouped under one label in discount
    // breakdowns instead of thousands of single-use codes. CAP-xxxxxxxxxxxx
    // confirmed by the user as Rivo loyalty redemptions (fixed $5–$100,
    // order-level; arrive as a code at checkout and as a manual discount on
    // Recharge renewals — the pattern catches both). BIO-xxxxxxxxxxxx is
    // the same on BioSteel (confirmed by the user 2026-10-20; fixed
    // $1–$100 amounts in the sample). Anchored to the 12 hex characters
    // every real code has (all 1,578 CAP- codes checked), because BioSteel
    // also runs an automatic discount titled "BIO-STEAL" that isn't Rivo.
    'label_groups' => [
        '/^CAP-[0-9a-f]{12}$/i' => 'Rivo loyalty',
        '/^BIO-[0-9a-f]{12}$/i' => 'Rivo loyalty',
    ],

    // Subscription lines' list price is derived from one-time sales of the
    // same variant (the selling-plan discount is baked into the line's own
    // original price, not a discount allocation). Look this far either side
    // of the order date for a one-time sale before falling back to the
    // variant's current price.
    'list_price_window_days' => 90,

    // asp:process-pending works through at most this many queued orders per
    // run (it runs every minute).
    'pending_batch_size' => 200,
];
