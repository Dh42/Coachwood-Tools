<?php

use App\Scheduling\TaskRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * The VC and SC Listings Editors: two tools with separate permissions, since
 * they are separate Amazon accounts (names and descriptions as the old app's
 * admins last set them). The daily audit runs at 08:30 UTC until cutover,
 * after the old app's 08:00 run, since both apps share one SP-API rate
 * limit (decision 2026-12-06; PlatformImporter::SHIFTED_UNTIL_CUTOVER).
 * `legacy:import-platform` then copies the old order, active flags and the
 * task's enabled flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        ToolRegistry::register([
            'slug' => 'vc-listings',
            'name' => 'VC Listings Editor',
            'description' => 'Edits live Amazon Vendor Central listing content — title, bullets, description, nutrition facts, and any other attribute Amazon returns — plus a completeness audit across the whole catalog.',
            'group' => 'Amazon · Listings',
            'icon' => 'edit',
            'url' => '/tools/vc-listings',
        ]);
        ToolRegistry::register([
            'slug' => 'sc-listings',
            'name' => 'SC Listings Editor',
            'description' => 'The Seller Central (US) counterpart to the VC Listings Editor — edits live listing content and audits completeness for products sold through the Seller Central account.',
            'group' => 'Amazon · Listings',
            'icon' => 'globe',
            'url' => '/tools/sc-listings',
        ]);

        TaskRegistry::register([
            'command' => 'listings:audit-run',
            'name' => 'Listings Audit Run',
            'description' => 'Runs the full Listings Audit (issues, A+ status, image count, contribution drift) for every active Vendor and Seller Central product — keeps the LI column and everything else the audit populates from going stale if nobody clicks Audit Listings.',
            'cron_expression' => '30 8 * * *',
        ]);
    }

    public function down(): void
    {
        TaskRegistry::unregister('listings:audit-run');
        ToolRegistry::unregister('sc-listings');
        ToolRegistry::unregister('vc-listings');
    }
};
