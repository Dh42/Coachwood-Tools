<?php

/*
 * Moving tools from the old app one at a time (build spec section 14.3,
 * decision 2026-12-07). Each group moves together: its tools, the tables
 * its imports copy, its scheduled tasks and its external writes. Groups are
 * in the plan's step order, which is also `legacy:sync`'s order.
 *
 * - tools: tool slugs here
 * - imports / compares: the commands that copy and check its tables while
 *   the old app still owns them
 * - task_prefixes: scheduled task commands that belong to it (matched on
 *   the command's name, before any argument)
 * - after_move: steps `cutover:move` lists for after the move
 *
 * Which groups have moved is stored in `tool_cutovers` (cutover:move).
 */
return [
    // Run by legacy:sync whatever has moved: the platform until the final
    // cutover, and the product lists and traffic until Phase 1 rebuilds them.
    'shared_imports' => ['legacy:import-platform', 'legacy:import-products', 'legacy:import-vc-traffic'],

    'groups' => [
        'team-app-stats' => [
            'label' => 'BioSteel App Stats',
            'tools' => ['team-app-stats'],
            'imports' => ['legacy:import-team-app-stats'],
            'compares' => ['compare:team-app-stats'],
            'task_prefixes' => ['team-app-stats:'],
        ],
        'walmart-us' => [
            'label' => 'Walmart US Listing Auditor',
            'tools' => ['walmart-us-listing-auditor'],
            'imports' => ['legacy:import-walmart-us'],
            'compares' => ['compare:walmart-us'],
            'task_prefixes' => ['walmart-us:'],
        ],
        'us-product-reviews' => [
            'label' => 'US Product Reviews',
            'tools' => ['us-product-reviews'],
            'imports' => ['legacy:import-us-product-reviews'],
            'compares' => ['compare:us-product-reviews'],
            'task_prefixes' => ['feedback:'],
        ],
        'lost-sales' => [
            'label' => 'BioSteel Shopify Lost Sales',
            'tools' => ['biosteel-shopify-lost-sales'],
            'imports' => ['legacy:import-lost-sales'],
            'compares' => ['compare:lost-sales'],
            'task_prefixes' => ['stockout:'],
            'after_move' => ['Run `stockout:register-webhook`, check that inventory updates arrive at /webhooks/shopify/inventory-levels-update, then remove the old app\'s subscription in Shopify.'],
        ],
        'cdq-report' => [
            'label' => 'CDQ Listing Quality Report',
            'tools' => ['cdq-report'],
            'imports' => ['legacy:import-cdq-report'],
            'compares' => ['compare:cdq-report'],
            'task_prefixes' => ['cdq:'],
            'after_move' => ['The old app has no Anthropic key, so nothing else changes: `cdq:consistency` already runs here.'],
        ],
        'asp-tracker' => [
            'label' => 'ASP Trackers (Canadian Protein and BioSteel)',
            'tools' => ['asp-tracker', 'asp-tracker-bs'],
            'imports' => ['legacy:import-asp-tracker'],
            'compares' => ['compare:asp-tracker'],
            'task_prefixes' => ['asp:'],
            'after_move' => [
                'Run `asp:register-webhooks CP` and `asp:register-webhooks BS`, check that orders arrive, then remove the old app\'s subscriptions in Shopify.',
                'If a backfill is needed, enable `asp:backfill CP` / `asp:backfill BS` in Admin > Scheduler (they stay off until then).',
            ],
        ],
        'team-sponsorship' => [
            'label' => 'Team Sponsorship and Sponsorship Stats',
            'tools' => ['team-sponsorship', 'team-sponsorship-stats'],
            'imports' => ['legacy:import-team-sponsorship'],
            'compares' => ['compare:team-sponsorship'],
            'task_prefixes' => ['team-sponsorship:'],
            'after_move' => ['Send one real sponsorship and check the draft order, the invoice email and the Klaviyo event.'],
        ],
        // One group: the Image Manager logs to the editors' `listing_edits`,
        // and SC's Sync Products owns `sc_products` once this has moved.
        'listings' => [
            'label' => 'VC and SC Listings Editors, VC Image Manager',
            'tools' => ['vc-listings', 'sc-listings', 'vc-image-manager'],
            'imports' => ['legacy:import-listings', 'legacy:import-image-manager'],
            'compares' => ['compare:listings', 'compare:image-manager'],
            'task_prefixes' => ['listings:'],
            'after_move' => [
                'In Admin > Scheduler, set `listings:audit-run` back to 0 8 * * * (it ran at 08:30 beside the old app).',
                'Make one small real edit in each editor and check it in Seller and Vendor Central.',
                'Before `tools.biosteel.com` moves here, copy the image files (README, VC Image Manager).',
            ],
        ],
    ],
];
