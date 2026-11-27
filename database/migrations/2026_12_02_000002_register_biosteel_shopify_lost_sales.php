<?php

use App\Scheduling\TaskRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * BioSteel Shopify Lost Sales (decision D7: renamed from Stockout, new URL
 * and permission, the old URL redirects) and its two scheduled tasks. The
 * platform import copies the sweep's enabled flag from the old app and
 * gives the clean-up the same flag (decision 2026-12-01).
 */
return new class extends Migration
{
    private const TASKS = ['stockout:snapshot-inventory', 'stockout:prune-snapshots'];

    public function up(): void
    {
        ToolRegistry::register([
            'slug' => 'biosteel-shopify-lost-sales',
            'name' => 'BioSteel Shopify Lost Sales',
            'description' => "Gross revenue lost to core-product (merch excluded) stockouts, reconstructed from Shopify's own historical inventory and sales data — ranked by dollar impact, with a deliberate exception for RTD tetra-pack SKUs whose PET-bottle successor was in stock instead.",
            'group' => 'BioSteel Shopify',
            'icon' => 'trend-down',
            'url' => '/tools/biosteel-shopify-lost-sales',
        ]);

        TaskRegistry::register([
            'command' => 'stockout:snapshot-inventory',
            'name' => 'Lost Sales Inventory Snapshot',
            'description' => 'Sweeps current inventory quantities for every tracked BioSteel variant into inventory_snapshots. Until cutover this is the only source of snapshots: no webhook points at this app.',
            'cron_expression' => '*/15 * * * *',
        ]);
        TaskRegistry::register([
            'command' => 'stockout:prune-snapshots',
            'name' => 'Lost Sales Snapshot Clean-up',
            'description' => 'Deletes inventory_snapshots older than STOCKOUT_SNAPSHOT_RETENTION_DAYS (default 90), in small paced batches. Enabled whenever the sweep is.',
            'cron_expression' => '17 * * * *',
        ]);
    }

    public function down(): void
    {
        foreach (self::TASKS as $command) {
            TaskRegistry::unregister($command);
        }

        ToolRegistry::unregister('biosteel-shopify-lost-sales');
    }
};
