<?php

use App\Scheduling\TaskRegistry;
use App\Tools\ToolRegistry;
use Illuminate\Database\Migrations\Migration;

/**
 * US Product Reviews: the tool and its two weekly collectors, as in the old
 * app, plus the daily `legacy:import-products` that keeps the product lists
 * the collectors read in step with the old app until Phase 1.
 */
return new class extends Migration
{
    private const TASKS = ['feedback:collect', 'feedback:collect-trends', 'legacy:import-products'];

    public function up(): void
    {
        ToolRegistry::register([
            'slug' => 'us-product-reviews',
            'name' => 'US Product Reviews',
            'description' => "Amazon's own review-topic classification (Customer Feedback API) for every ASIN across both the VC (.ca) and SC (US) catalogs, queried against the US marketplace — the only one this API supports that we operate in. Not raw reviews: topic/subtopic occurrence %, mention counts, star-rating impact vs. category, and up to 3 verbatim snippets per topic, plus a 6-month occurrence trend.",
            'group' => 'Amazon · Listings',
            'icon' => 'star',
            'url' => '/tools/us-product-reviews',
        ]);

        TaskRegistry::register([
            'command' => 'feedback:collect',
            'name' => 'US Product Reviews Collect',
            'description' => 'Pulls Customer Feedback review topics for every ASIN across both catalogs (US marketplace), one request a second. Weekly.',
            'cron_expression' => '0 1 * * 0',
        ]);
        TaskRegistry::register([
            'command' => 'feedback:collect-trends',
            'name' => 'US Product Reviews Collect Trends',
            'description' => 'Pulls the 6-month occurrence-% trend per topic for every ASIN across both catalogs (US marketplace). A second call per ASIN, so it runs separately from the topics collector. Weekly.',
            'cron_expression' => '0 2 * * 0',
        ]);
        TaskRegistry::register([
            'command' => 'legacy:import-products',
            'name' => 'Products Sync From Old App',
            'description' => 'Copies products and sc_products from the old app (read-only), so tools reading them stay current. Until Phase 1 rebuilds the catalog.',
            'cron_expression' => '30 0 * * *',
        ]);
    }

    public function down(): void
    {
        foreach (self::TASKS as $command) {
            TaskRegistry::unregister($command);
        }

        ToolRegistry::unregister('us-product-reviews');
    }
};
