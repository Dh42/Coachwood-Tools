<?php

namespace Tests\Unit\Services;

use App\Models\WalmartUsCatalogScore;
use App\Models\WalmartUsItem;
use App\Models\WalmartUsListingAudit;
use App\Models\WalmartUsListingAuditIssue;
use App\Services\WalmartUs\WalmartUsAuditReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WalmartUsAuditReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_catalog_score_returns_null_with_no_rows(): void
    {
        $this->assertNull((new WalmartUsAuditReportService)->latestCatalogScore());
    }

    public function test_latest_catalog_score_returns_the_most_recent_row(): void
    {
        WalmartUsCatalogScore::create(['run_at' => now()->subDay(), 'listing_quality' => 50, 'item_count' => 10]);
        WalmartUsCatalogScore::create(['run_at' => now(), 'listing_quality' => 80, 'item_count' => 12]);

        $result = (new WalmartUsAuditReportService)->latestCatalogScore();

        $this->assertSame(80.0, $result['listing_quality']);
        $this->assertSame(12, $result['item_count']);
    }

    public function test_catalog_score_history_is_windowed_and_ordered(): void
    {
        WalmartUsCatalogScore::create(['run_at' => now()->subDays(40), 'listing_quality' => 10]);
        WalmartUsCatalogScore::create(['run_at' => now()->subDays(2), 'listing_quality' => 60]);
        WalmartUsCatalogScore::create(['run_at' => now()->subDay(), 'listing_quality' => 70]);

        $history = (new WalmartUsAuditReportService)->catalogScoreHistory(30);

        $this->assertCount(2, $history);
        $this->assertSame(60.0, $history[0]['listing_quality']);
        $this->assertSame(70.0, $history[1]['listing_quality']);
    }

    public function test_current_audits_returns_only_the_latest_audit_per_sku(): void
    {
        WalmartUsItem::create(['sku' => 'SKU-1', 'product_name' => 'Widget One']);
        WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-09-01', 'run_at' => Carbon::parse('2026-09-01 06:00'), 'listing_quality' => 40]);
        $latest = WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-09-02', 'run_at' => Carbon::parse('2026-09-02 06:00'), 'listing_quality' => 90]);

        $audits = (new WalmartUsAuditReportService)->currentAudits();

        $this->assertCount(1, $audits);
        $this->assertSame(90.0, $audits->first()['listing_quality']);
        $this->assertSame('Widget One', $audits->first()['product_name']);
    }

    public function test_current_audits_counts_issues_on_the_latest_audit(): void
    {
        $audit = WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-09-01', 'run_at' => now(), 'listing_quality' => 40]);
        WalmartUsListingAuditIssue::create(['audit_id' => $audit->id, 'source' => 'walmart', 'code' => 'A']);
        WalmartUsListingAuditIssue::create(['audit_id' => $audit->id, 'source' => 'walmart', 'code' => 'B']);

        $audits = (new WalmartUsAuditReportService)->currentAudits();

        $this->assertSame(2, $audits->first()['issue_count']);
    }

    public function test_top_issues_by_occurrence_only_counts_the_latest_audit_per_sku(): void
    {
        $stale = WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-09-01', 'run_at' => Carbon::parse('2026-09-01')]);
        $latest = WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-09-02', 'run_at' => Carbon::parse('2026-09-02')]);
        WalmartUsListingAuditIssue::create(['audit_id' => $stale->id, 'source' => 'walmart', 'code' => 'STALE_ISSUE']);
        WalmartUsListingAuditIssue::create(['audit_id' => $latest->id, 'source' => 'walmart', 'code' => 'CURRENT_ISSUE']);
        WalmartUsListingAuditIssue::create(['audit_id' => $latest->id, 'source' => 'walmart', 'code' => 'CURRENT_ISSUE']);

        $topIssues = (new WalmartUsAuditReportService)->topIssuesByOccurrence();

        $this->assertCount(1, $topIssues);
        $this->assertSame('CURRENT_ISSUE', $topIssues->first()['code']);
        $this->assertSame(2, $topIssues->first()['occurrences']);
    }

    public function test_audit_for_sku_returns_null_for_an_unaudited_sku(): void
    {
        $this->assertNull((new WalmartUsAuditReportService)->auditForSku('NOPE'));
    }

    public function test_audit_for_sku_returns_the_latest_audit_and_its_issues(): void
    {
        WalmartUsItem::create(['sku' => 'SKU-1', 'product_name' => 'Widget One']);
        WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-09-01', 'run_at' => Carbon::parse('2026-09-01')]);
        $latest = WalmartUsListingAudit::create(['sku' => 'SKU-1', 'as_of_date' => '2026-09-02', 'run_at' => Carbon::parse('2026-09-02')]);
        WalmartUsListingAuditIssue::create(['audit_id' => $latest->id, 'source' => 'walmart', 'code' => 'ISSUE']);

        $result = (new WalmartUsAuditReportService)->auditForSku('SKU-1');

        $this->assertSame($latest->id, $result['audit']->id);
        $this->assertSame('Widget One', $result['product_name']);
        $this->assertCount(1, $result['issues']);
    }
}
