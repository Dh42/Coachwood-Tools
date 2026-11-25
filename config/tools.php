<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External writes
    |--------------------------------------------------------------------------
    |
    | Master switch for every action that changes an outside system: Shopify
    | draft orders, invoices and refunds, Klaviyo events, Amazon listing and
    | image submissions. While false, those actions run their preview path
    | only and report that writes are disabled. It stays false until cutover
    | (docs/phase-0-plan.md, section 6). Read it only through
    | App\Support\ExternalWrites, never with env() or config() directly.
    |
    */

    'external_writes' => (bool) env('TOOLS_EXTERNAL_WRITES', false),

];
