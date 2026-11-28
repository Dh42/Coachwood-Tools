<?php

return [

    /*
    |--------------------------------------------------------------------------
    | BioSteel Team Sponsorship — Shopify product mapping
    |--------------------------------------------------------------------------
    |
    | Not secrets — just the specific product/variant/location IDs this one
    | tool sponsors. Kept separate from config/services.php, which holds the
    | actual API credentials.
    |
    */

    'bottles' => [
        'label' => 'Bottles',
        'product_id' => 1733414289526,
        'variant_id' => 15724230574198,
    ],

    'sample_packs' => [
        'label' => 'Sample Packs',
        'product_id' => 7781467193462,
        'variant_id' => 42391614292086,
    ],

    'team_towels' => [
        'label' => 'Team Towels',
        'product_id' => 1733442404470,
        'variant_id' => 15724432687222,
        // One towel per this many bottles, capped at max_per_order.
        'per_bottles' => 5,
        'max_per_order' => 3,
    ],

    // Only added to hockey teams' orders (matched against the team's BioSteel
    // sportType), at the same quantity as bottles.
    'hockey_pucks' => [
        'label' => 'Hockey Pucks',
        'product_id' => 8218402455670,
        'variant_id' => 43926474162294,
    ],

    'location_id' => 62882513014,

];
