<?php

use App\Scheduling\TaskRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * CDQ Listing Quality Report: the tool and its four Sunday tasks at the old
 * schedules, plus a daily copy of `vc_traffic_current` from the old app.
 */
return new class extends Migration
{
    private const TASKS = ['cdq:sync-schemas', 'cdq:snapshot', 'cdq:score', 'cdq:consistency', 'legacy:import-vc-traffic'];

    public function up(): void
    {
        ToolRegistry::register([
            'slug' => 'cdq-report',
            'name' => 'CDQ Listing Quality Report',
            'description' => 'Amazon Vendor Central content quality scores (0–100, our own CDQ-modeled deduction score) for every active ASIN — grade distribution, top defects by points lost, and a per-ASIN drill-down.',
            'group' => 'Amazon · Listings',
            'icon' => 'check',
            'url' => '/tools/cdq-report',
        ]);

        TaskRegistry::register([
            'command' => 'cdq:sync-schemas',
            'name' => 'CDQ Schema Sync',
            'description' => 'Fetches and flattens Product Type Definitions (Required/Recommended attributes) for every real product type seen in the active catalog. Schemas change rarely — weekly is generous.',
            'cron_expression' => '0 4 * * 0',
        ]);
        TaskRegistry::register([
            'command' => 'cdq:snapshot',
            'name' => 'CDQ Listing Snapshot',
            'description' => "Snapshots Catalog Items + A+ + Listings Items issues into cdq_listing_snapshot for every active Vendor Central ASIN, deduped by content_hash. Weekly cadence per the CDQ brief — Amazon's own CDQ score recalculates in batches, not real time.",
            'cron_expression' => '15 4 * * 0',
        ]);
        TaskRegistry::register([
            'command' => 'cdq:score',
            'name' => 'CDQ Deterministic Score',
            'description' => "Scores every ASIN's latest snapshot against the current check-config revision (ATTR/TEXT/IMAGE/APLUS + hard fails). No live API calls — pure recompute off already-captured snapshots.",
            'cron_expression' => '45 4 * * 0',
        ]);
        TaskRegistry::register([
            'command' => 'cdq:consistency',
            'name' => 'CDQ Consistency Pass',
            'description' => 'LLM cross-field consistency check (CONSIST dimension) via the Anthropic API, cached on content_hash. Needs ANTHROPIC_API_KEY.',
            'cron_expression' => '15 5 * * 0',
        ]);
        TaskRegistry::register([
            'command' => 'legacy:import-vc-traffic',
            'name' => 'VC Traffic Sync From Old App',
            'description' => 'Copies vc_traffic_current from the old app (read-only), for CDQ\'s glance-view weights. Until Phase 1 replaces it with weekly metrics.',
            'cron_expression' => '40 0 * * *',
        ]);
    }

    public function down(): void
    {
        foreach (self::TASKS as $command) {
            TaskRegistry::unregister($command);
        }

        ToolRegistry::unregister('cdq-report');
    }
};
