<?php

use App\Scheduling\TaskRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * Walmart US Listing Auditor: the tool and its nightly audit task, as in the
 * old app. `legacy:import-platform` then copies the old order, active flag
 * and task schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        ToolRegistry::register([
            'slug' => 'walmart-us-listing-auditor',
            'name' => 'Walmart US Listing Auditor',
            'description' => "Walmart's own Listing Quality scores and issues for every audited US Marketplace SKU — catalog rollup, top issues by occurrence, and a per-SKU drill-down. Our own internal rule scoring isn't implemented yet.",
            'group' => 'Walmart',
            'icon' => 'cart',
            'url' => '/tools/walmart-us-listing-auditor',
        ]);

        TaskRegistry::register([
            'command' => 'walmart-us:listing-audit',
            'name' => 'Walmart US Listing Audit',
            'description' => "Pulls the seller catalog and Walmart's own Listing Quality scores/issues for every active US Marketplace SKU, writing one dated snapshot per run. Nightly, after Walmart's own daily score recalculation (the LISTING_QUALITY report reflects the previous day's data).",
            'cron_expression' => '0 6 * * *',
        ]);
    }

    public function down(): void
    {
        TaskRegistry::unregister('walmart-us:listing-audit');
        ToolRegistry::unregister('walmart-us-listing-auditor');
    }
};
