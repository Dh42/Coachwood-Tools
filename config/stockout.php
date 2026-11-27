<?php

return [

    // BioSteel only in v1. Originally reused Team Sponsorship/Combined
    // Shipping's shared Shopify token (services.shopify /
    // combined_shipping.brands.BS), but that app's `read_locations` scope
    // grant never actually attached to its token (see context.md
    // 2026-09-19 shelving note) — so Stockout was migrated to its own
    // dedicated custom app instead, installed 2026-09-27 via `shopify dev`
    // (Partner Dashboard/CLI), not the classic Admin > Develop apps flow.
    // `store` is still the shared env var (same physical store, not
    // app-specific); client_id/client_secret below are this app's own and
    // are NOT used by any other tool — do not point Team Sponsorship or
    // Combined Shipping at them, and do not repoint this at
    // SHOPIFY_ACCESS_TOKEN.
    //
    // access_token is deliberately left blank in every real environment: a
    // CLI/Partner-Dashboard custom app has no static "reveal token" screen,
    // only the client credentials grant (POST /admin/oauth/access_token
    // with client_id/client_secret), which returns a token that expires in
    // ~24h — confirmed live 2026-09-27, `expires_in: 86399`. ShopifyGraphQlClient
    // fetches and caches one dynamically when this is blank; a value here
    // is only an escape hatch for tests / a future long-lived token.
    'shopify' => [
        'store' => env('SHOPIFY_STORE'),
        'client_id' => env('STOCKOUT_SHOPIFY_CLIENT_ID'),
        'client_secret' => env('STOCKOUT_SHOPIFY_CLIENT_SECRET'),
        'access_token' => env('STOCKOUT_SHOPIFY_ACCESS_TOKEN'),
    ],

    // Pinned, not "latest" — confirmed live (2026-09-15) against the real
    // BioSteel store: productVariants, inventoryItem, inventoryLevels,
    // quantities(names:), and webhookSubscriptionCreate all resolve
    // correctly against 2026-07. Reused from Combined Shipping's own
    // pinned version rather than re-deciding independently.
    'api_version' => '2026-07',

    // Shopify signs every webhook with the owning app's client secret
    // (Admin > Settings > Apps > Develop apps > this app > API credentials
    // > "Client secret") via HMAC-SHA256 in the X-Shopify-Hmac-Sha256
    // header — NOT the access token above. This app's own client secret,
    // distinct from Team Sponsorship/Combined Shipping's token. Must be set
    // before the webhook subscription is created; VerifyShopifyWebhook
    // rejects every request with 401 while this is blank rather than
    // skipping verification.
    'webhook_secret' => env('STOCKOUT_SHOPIFY_CLIENT_SECRET'),

    // Where Shopify should POST inventory_levels/update events. Must be a
    // real, publicly-reachable HTTPS URL before stockout:register-webhook
    // is run — there's no tunnel/ngrok path for this app, so local dev
    // can exercise the receiver (VerifyShopifyWebhook + the controller)
    // via tests only, never against a real Shopify-delivered event.
    'webhook_callback_url' => env('APP_URL').'/webhooks/shopify/inventory-levels-update',

    // Every named inventory quantity state the cron sweep captures per
    // (variant, location) reading. Confirmed live which names this store's
    // API version actually returns; captured as separate rows per the
    // "store raw, don't aggregate" rule even though Phase 3 only consumes
    // `available`.
    'tracked_states' => ['available', 'committed', 'on_hand'],

    // Open Q3 (deferred at Phase 1, since capture is location-agnostic by
    // design) answered directly by the user 2026-09-27: BioSteel operates
    // from exactly one location, Sylvestre. The store also has three other
    // locations Shopify returns — confirmed live via
    // locations(includeLegacy: true, includeInactive: true), which the
    // default (no-args) locations() query already excludes: Printify
    // (66815492214, a 3rd-party print-on-demand app), ShipBobFulfillment-433076
    // (75641290870, a 3rd-party fulfillment app), and SCI (11754963062,
    // inactive/legacy) — none of these should count toward stockout
    // detection. Same ID already hardcoded in config/team_sponsorship.php's
    // own 'location_id', confirmed to match. NOT yet consumed anywhere —
    // Phase 1's sweep still captures every location it finds raw, per the
    // "store raw, filter at read-time" rule; this is here for Phase 3's
    // aggregation to filter against once that phase starts.
    // Days of inventory_snapshots that stockout:prune-snapshots keeps. The
    // sweep adds ~333k rows a day. No page or job reads this table yet (the
    // estimator uses inventory_history_daily); it's raw history kept for later.
    'snapshot_retention_days' => (int) (env('STOCKOUT_SNAPSHOT_RETENTION_DAYS') ?: 90),

    'tracked_location_id' => 'gid://shopify/Location/62882513014',

    // Confirmed with the user 2026-09-27: these products are never a real
    // stockout worth measuring (giveaway/sample items, not sold SKUs in
    // their own right) and should be dropped at ingestion time, not carried
    // through as noise. Matched by exact product_title — this is also the
    // only granularity the ShopifyQL historical dataset groups by (see
    // stockout:import-shopifyql-history), so title is the natural join key
    // for both the live and historical paths. "BIOSTEEL TEAM BOTTLE"
    // (SKU 0303C-SH) is excluded per the user's explicit choice; the
    // similarly-named "BioSteel Spouted Team Bottle" (SKU 0303K) is a
    // different product and stays tracked.
    'excluded_product_titles' => [
        'BIOSTEEL - Protein Sample Kit',
        'BIOSTEEL - Hydration Sample Kit',
        'RTD Sample Box',
        'BIOSTEEL - Team Sponsorship Starter Kit',
        'BIOSTEEL TEAM BOTTLE',
    ],

    // The one tracked variant found (out of 1,985 swept live 2026-09-27)
    // whose inventoryPolicy is CONTINUE rather than DENY — it never blocks a
    // sale at zero stock, so per the brief's own rule (§6) it can never
    // register a real stockout and must be excluded from the denominator.
    'excluded_variant_ids' => [
        'gid://shopify/ProductVariant/42574782759030', // Premium Electric Mixer
    ],

    // Confirmed with the user 2026-09-28: BioSteel's core business is
    // supplements/drinks — merch is something they "dabble in," not worth
    // measuring lost sales on. Matched by exact `productType` (confirmed
    // live against the full catalog's real type values, not guessed).
    // Real, known limitation: 106 of 471 products have a BLANK productType,
    // a genuine mix of real core SKUs (HYDRATION MIX, Creatine, Beta-Alanine)
    // and real merch (coolers, tote bags, starter kits) with no type set at
    // all — this filter can't sort those, since there's nothing on them to
    // filter. The user's chosen fix is tagging those properly in Shopify
    // Admin over time (each one picked up here for free once tagged), not
    // an app-side title-keyword guess — "works for the time being" as of
    // this decision, revisit only if that retagging stalls.
    'excluded_product_types' => [
        'Merchandise',
        'Hoodie',
        'T-Shirt',
        'Sweatshirt',
        'Accessories',
        'Kids clothes',
        'bundle',
        'Bags',
        'Home Decor',
        'Poster',
        'All Over Prints',
        'Puzzle',
    ],

    // RTD tetra-pack -> PET-bottle migration (see RtdPetSuccessorResolver's
    // own docblock). Confirmed live 2026-09-28: the transform is a plain
    // strip-prefix/append-suffix on the SKU (RTD-WF-12 -> WF-12-PET), not a
    // pattern on the product title (RTD titles read "SPORTS DRINK / …", PET
    // ones "Hydration Drink / …" — no consistent title-level rule exists).
    'rtd_successor_sku_prefix_to_strip' => 'RTD-',
    'rtd_successor_sku_suffix_to_append' => '-PET',

];
