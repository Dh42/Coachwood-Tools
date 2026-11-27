<?php

namespace Tests\Unit\Services\Cdq;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqDefect;
use App\Models\CdqListingSnapshot;
use App\Models\CdqScore;
use App\Models\VcTrafficCurrent;
use App\Services\Cdq\CdqReportService;
use Database\Seeders\CdqCheckConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CdqReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CdqCheckConfigSeeder::class);
    }

    private function version(): CdqCheckConfigVersion
    {
        return CdqCheckConfigVersion::orderByDesc('effective_from')->first();
    }

    private function snapshotWithScore(array $snapshotOverrides = [], array $scoreOverrides = []): CdqListingSnapshot
    {
        $snapshot = CdqListingSnapshot::create(array_merge([
            'asin' => 'B0ONE',
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => []]]],
            'attributes_json' => [],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ], $snapshotOverrides));

        CdqScore::create(array_merge([
            'snapshot_id' => $snapshot->id,
            'check_config_version_id' => $this->version()->id,
            'attr_score' => 30, 'text_score' => 20, 'image_score' => 20, 'aplus_score' => 20, 'consist_score' => 10,
            'total_score' => 100, 'grade' => 'A', 'scored_at' => now(),
        ], $scoreOverrides));

        return $snapshot;
    }

    public function test_grade_distribution_counts_the_latest_score_per_asin(): void
    {
        $this->snapshotWithScore(['asin' => 'B0A'], ['grade' => 'A', 'total_score' => 95]);
        $this->snapshotWithScore(['asin' => 'B0B'], ['grade' => 'D', 'total_score' => 40]);

        $counts = (new CdqReportService)->gradeDistribution();

        $this->assertSame(1, $counts['A']);
        $this->assertSame(1, $counts['D']);
        $this->assertSame(0, $counts['C']);
    }

    public function test_only_the_latest_snapshot_per_asin_is_counted(): void
    {
        $s1 = $this->snapshotWithScore(['asin' => 'B0DUP', 'content_hash' => hash('sha256', 'v1')], ['grade' => 'D', 'total_score' => 40]);
        $s2 = $this->snapshotWithScore(['asin' => 'B0DUP', 'content_hash' => hash('sha256', 'v2')], ['grade' => 'A', 'total_score' => 95]);

        $counts = (new CdqReportService)->gradeDistribution();

        $this->assertSame(1, $counts['A']);
        $this->assertSame(0, $counts['D']);
    }

    public function test_week_over_week_movement_reports_no_prior_data_on_a_fresh_catalog(): void
    {
        $this->snapshotWithScore();

        $movement = (new CdqReportService)->weekOverWeekMovement();

        $this->assertFalse($movement['has_prior_data']);
    }

    public function test_week_over_week_movement_detects_improvement(): void
    {
        Carbon::setTestNow('2026-08-01');
        $version = $this->version();
        $old = CdqListingSnapshot::create([
            'asin' => 'B0MOVE', 'marketplace_id' => 'A2EUQ1WTGCTBG2', 'content_hash' => hash('sha256', 'old'),
            'catalog_json' => ['images' => [['images' => []]]], 'attributes_json' => [], 'issues_checked_at' => now(), 'captured_at' => now(),
        ]);
        CdqScore::create(['snapshot_id' => $old->id, 'check_config_version_id' => $version->id, 'attr_score' => 20, 'text_score' => 15, 'image_score' => 15, 'aplus_score' => 15, 'consist_score' => 10, 'total_score' => 75, 'grade' => 'B', 'scored_at' => now()]);

        Carbon::setTestNow('2026-08-10');
        $new = CdqListingSnapshot::create([
            'asin' => 'B0MOVE', 'marketplace_id' => 'A2EUQ1WTGCTBG2', 'content_hash' => hash('sha256', 'new'),
            'catalog_json' => ['images' => [['images' => []]]], 'attributes_json' => [], 'issues_checked_at' => now(), 'captured_at' => now(),
        ]);
        CdqScore::create(['snapshot_id' => $new->id, 'check_config_version_id' => $version->id, 'attr_score' => 30, 'text_score' => 20, 'image_score' => 20, 'aplus_score' => 20, 'consist_score' => 10, 'total_score' => 100, 'grade' => 'A', 'scored_at' => now()]);

        $movement = (new CdqReportService)->weekOverWeekMovement();

        $this->assertTrue($movement['has_prior_data']);
        $this->assertSame(1, $movement['improved']);
        $this->assertEquals(25.0, $movement['avg_change']);

        Carbon::setTestNow();
    }

    public function test_top_defects_by_points_lost_sums_across_the_catalog(): void
    {
        $s1 = $this->snapshotWithScore(['asin' => 'B0X']);
        $s2 = $this->snapshotWithScore(['asin' => 'B0Y']);
        CdqDefect::create(['snapshot_id' => $s1->id, 'dimension' => 'TEXT', 'check_code' => 'DESCRIPTION_MISSING', 'severity' => 'ERROR', 'points' => 5]);
        CdqDefect::create(['snapshot_id' => $s2->id, 'dimension' => 'TEXT', 'check_code' => 'DESCRIPTION_MISSING', 'severity' => 'ERROR', 'points' => 5]);

        $top = (new CdqReportService)->topDefectsByPointsLost();

        $this->assertEquals(10.0, $top->first()['points_lost']);
        $this->assertSame(2, $top->first()['occurrences']);
    }

    public function test_parent_image_rollup_uses_equal_weight_without_traffic_data(): void
    {
        $this->snapshotWithScore(['asin' => 'B0CHILD1', 'parent_asin' => 'B0PARENT'], ['image_score' => 10]);
        $this->snapshotWithScore(['asin' => 'B0CHILD2', 'parent_asin' => 'B0PARENT'], ['image_score' => 20]);

        $rollup = (new CdqReportService)->parentImageRollup();

        $this->assertEquals(15.0, $rollup['B0PARENT']['weighted_image_score']);
        $this->assertFalse($rollup['B0PARENT']['used_traffic_weighting']);
    }

    public function test_parent_image_rollup_weights_by_glance_views_when_available(): void
    {
        $this->snapshotWithScore(['asin' => 'B0CHILD1', 'parent_asin' => 'B0PARENT'], ['image_score' => 10]);
        $this->snapshotWithScore(['asin' => 'B0CHILD2', 'parent_asin' => 'B0PARENT'], ['image_score' => 20]);
        VcTrafficCurrent::create(['dedup_key' => 'k1', 'asin' => 'B0CHILD1', 'period_start' => now(), 'period_end' => now(), 'glance_views' => 900, 'pulled_at' => now()]);
        VcTrafficCurrent::create(['dedup_key' => 'k2', 'asin' => 'B0CHILD2', 'period_start' => now(), 'period_end' => now(), 'glance_views' => 100, 'pulled_at' => now()]);

        $rollup = (new CdqReportService)->parentImageRollup();

        // 10*(900/1000) + 20*(100/1000) = 9 + 2 = 11
        $this->assertEquals(11.0, $rollup['B0PARENT']['weighted_image_score']);
        $this->assertTrue($rollup['B0PARENT']['used_traffic_weighting']);
    }

    public function test_work_queue_sorts_by_points_lost_times_glance_views(): void
    {
        // Changed in the rebuild: the made-up ASINs were 12 and 13 characters,
        // longer than the 10-character asin column, which only SQLite let
        // through. CI here also tests on MariaDB.
        $this->snapshotWithScore(['asin' => 'B0LOWTRAFF'], ['total_score' => 50]); // points_lost 50
        $this->snapshotWithScore(['asin' => 'B0HIGHTRAF'], ['total_score' => 90]); // points_lost 10
        VcTrafficCurrent::create(['dedup_key' => 'k3', 'asin' => 'B0LOWTRAFF', 'period_start' => now(), 'period_end' => now(), 'glance_views' => 10, 'pulled_at' => now()]);
        VcTrafficCurrent::create(['dedup_key' => 'k4', 'asin' => 'B0HIGHTRAF', 'period_start' => now(), 'period_end' => now(), 'glance_views' => 10000, 'pulled_at' => now()]);

        $queue = (new CdqReportService)->workQueue();

        // 10 * 10000 = 100,000 beats 50 * 10 = 500 despite the worse score
        $this->assertSame('B0HIGHTRAF', $queue->first()['asin']);
    }
}
