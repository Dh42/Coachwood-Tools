<?php

use App\Scheduling\TaskRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * ASP Tracker: one tool per store (Canadian Protein, BioSteel), and four
 * tasks per store at the old schedules. `asp:backfill` is registered
 * disabled and stays so until cutover (plan section 2, rule 6): Shopify
 * allows one bulk operation per store at a time, and the old app's runs.
 */
return new class extends Migration
{
    private const STORES = [
        'CP' => ['slug' => 'asp-tracker', 'brand' => 'Canadian Protein', 'group' => 'Canadian Protein Shopify', 'catch_up' => '30 3 * * *', 'backfill' => '*/10 * * * *', 'rollups' => '0 4 * * *'],
        'BS' => ['slug' => 'asp-tracker-bs', 'brand' => 'BioSteel', 'group' => 'BioSteel Shopify', 'catch_up' => '45 3 * * *', 'backfill' => '5-59/10 * * * *', 'rollups' => '20 4 * * *'],
    ];

    public function up(): void
    {
        foreach (self::STORES as $store => $s) {
            ToolRegistry::register([
                'slug' => $s['slug'],
                'name' => "{$s['brand']} Average Selling Price Tracker",
                'description' => "What each {$s['brand']} product and variant actually sold for after every discount (sale pricing, subscriptions, Rivo and order codes), trended by day, week or month.",
                'group' => $s['group'],
                'icon' => 'tag',
                'url' => "/tools/{$s['slug']}",
            ]);

            TaskRegistry::register([
                'command' => "asp:process-pending {$store}",
                'name' => "ASP Tracker: process queued orders ({$store})",
                'description' => 'Re-fetches orders queued by Shopify webhooks and the nightly catch-up, and upserts their lines into asp_sales_lines.',
                'cron_expression' => '* * * * *',
            ]);
            TaskRegistry::register([
                'command' => "asp:catch-up {$store}",
                'name' => "ASP Tracker: nightly catch-up ({$store})",
                'description' => 'Queues every order updated in the last 48 hours for a re-fetch, covering any missed or failed webhook. Until cutover, the only way orders reach this app.',
                'cron_expression' => $s['catch_up'],
                'timezone' => 'America/Toronto',
            ]);
            TaskRegistry::register([
                'command' => "asp:backfill {$store}",
                'name' => "ASP Tracker: backfill ({$store})",
                'description' => 'Advances the one-time Shopify bulk-operation backfill (submit, poll, then import). Does nothing once the backfill has completed. Disabled until cutover.',
                'cron_expression' => $s['backfill'],
                'enabled' => false,
            ]);
            TaskRegistry::register([
                'command' => "asp:rebuild-rollups {$store}",
                'name' => "ASP Tracker: rebuild daily rollups ({$store})",
                'description' => 'Rebuilds the pre-summed daily tables the ASP Tracker page reads from. They are kept current as orders are written; this nightly full rebuild is the safety net.',
                'cron_expression' => $s['rollups'],
                'timezone' => 'America/Toronto',
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::STORES as $store => $s) {
            foreach (['asp:process-pending', 'asp:catch-up', 'asp:backfill', 'asp:rebuild-rollups'] as $command) {
                TaskRegistry::unregister("{$command} {$store}");
            }
            ToolRegistry::unregister($s['slug']);
        }
    }
};
