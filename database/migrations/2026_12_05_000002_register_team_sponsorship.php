<?php

use App\Scheduling\TaskRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * Team Sponsorship and its Stats page: two tools with separate permissions,
 * as in the old app (names and descriptions as its admins last set them),
 * beside App Stats in "BioSteel Teams". The daily activity refresh keeps its
 * old schedule. `legacy:import-platform` then copies the old order, active
 * flags and the task's enabled flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        ToolRegistry::register([
            'slug' => 'team-sponsorship',
            'name' => 'BioSteel Team Sponsorship',
            'description' => "Look up a team's sponsorship eligibility via the BioSteel Teams API and send their free sponsorship package through Shopify.",
            'group' => 'BioSteel Teams',
            'icon' => 'trophy',
            'url' => '/tools/team-sponsorship',
        ]);
        ToolRegistry::register([
            'slug' => 'team-sponsorship-stats',
            'name' => 'BioSteel Sponsorship Stats',
            'description' => 'Order volume, top requesters, and recent history for the BioSteel Team Sponsorship tool.',
            'group' => 'BioSteel Teams',
            'icon' => 'pie',
            'url' => '/tools/team-sponsorship/stats',
        ]);

        TaskRegistry::register([
            'command' => 'team-sponsorship:refresh-activity',
            'name' => 'Team Sponsorship Activity Refresh',
            'description' => "Resolves and caches each sponsored team's email reach and event/game/practice counts from the BioSteel Teams API, shown on the Team Sponsorship Stats page. Daily, not live-per-row, since resolving a team's BioSteel team_id requires paging the entire org-wide team roster.",
            'cron_expression' => '0 1 * * *',
        ]);
    }

    public function down(): void
    {
        TaskRegistry::unregister('team-sponsorship:refresh-activity');
        ToolRegistry::unregister('team-sponsorship-stats');
        ToolRegistry::unregister('team-sponsorship');
    }
};
