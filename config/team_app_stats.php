<?php

return [

    /*
    |--------------------------------------------------------------------------
    | BioSteel App Stats — Ad Manager / GA4 reference IDs
    |--------------------------------------------------------------------------
    |
    | Not secrets — these identify one specific pre-built report and two
    | specific GA4 properties on the shared Google service account (see
    | config('services.google_team_app_stats.key_file')). Kept separate from
    | config/services.php, which holds the actual credentials, following the
    | same split as config/team_sponsorship.php's Shopify product IDs.
    |
    */

    // Pre-built Ad Manager report (dimensions: DATE; metrics in this order:
    // impressions, clicks, ad requests, viewable impressions, measurable
    // impressions; dateRange: relative YESTERDAY). Created once, out-of-band,
    // against the live Ad Manager API — not something this app creates.
    'gam_report_name' => 'networks/23317687902/reports/7931974561',

    // The BioSteel Teams app's own GA4 property.
    'ga4_property_id' => '497789201',

    // The main biosteel.com marketing site's GA4 property — a different
    // property than the one above, queried only for QR-code sponsorship
    // campaign traffic (see ga4_sponsor_campaign below), never the whole site.
    'ga4_sponsor_property_id' => '329277114',

    // Session campaign name (exact match, case-sensitive) used by the QR
    // code on team-sponsorship packaging. If the campaign name on the
    // packaging/QR code ever changes, update this — otherwise the sponsor
    // section just silently reports zeros for the old name.
    'ga4_sponsor_campaign' => 'GetSponsored_QR_Packaging_Scan',

    // The social snapshot runs at 23:55 here and is stamped with this
    // timezone's date (found issue 12: it used the UTC date, a day ahead).
    'social_timezone' => 'America/Toronto',

];
