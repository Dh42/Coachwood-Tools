<?php

namespace App\Services\WalmartUs;

use App\Models\WalmartUsCatalogScore;
use App\Models\WalmartUsItem;
use App\Models\WalmartUsListingAudit;
use App\Models\WalmartUsListingAuditIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only aggregation over Phase 1's ingested tables for the reporting UI
 * — pure/offline, never calls WalmartUsApiClient itself. Mirrors
 * CdqReportService's shape (latest-row-per-key via a MAX(id) subquery,
 * honest empty states instead of fabricated placeholders).
 *
 * our_rule_score is never surfaced here — it's null for every row until a
 * later phase's rule engine exists (blocked on confirming a real
 * content-data source; see the Phase 2 plan).
 */
class WalmartUsAuditReportService
{
    /** Most recent walmart_us_catalog_scores row, or null if no run has ever written one. */
    public function latestCatalogScore(): ?array
    {
        $row = WalmartUsCatalogScore::orderByDesc('run_at')->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }

        return [
            'run_at' => $row->run_at,
            'listing_quality' => $row->listing_quality !== null ? (float) $row->listing_quality : null,
            'content_score' => $row->content_score !== null ? (float) $row->content_score : null,
            'offer_score' => $row->offer_score !== null ? (float) $row->offer_score : null,
            'rating_review_score' => $row->rating_review_score !== null ? (float) $row->rating_review_score : null,
            'price_score' => $row->price_score !== null ? (float) $row->price_score : null,
            'shipping_score' => $row->shipping_score !== null ? (float) $row->shipping_score : null,
            'transactibility_score' => $row->transactibility_score !== null ? (float) $row->transactibility_score : null,
            'item_defect_cnt' => $row->item_defect_cnt,
            'defect_ratio' => $row->defect_ratio !== null ? (float) $row->defect_ratio : null,
            'item_count' => $row->item_count,
        ];
    }

    /**
     * @return Collection<int, array{run_at: Carbon, listing_quality: ?float}>
     */
    public function catalogScoreHistory(int $days = 30): Collection
    {
        return WalmartUsCatalogScore::where('run_at', '>=', Carbon::now()->subDays($days))
            ->orderBy('run_at')
            ->get()
            ->map(fn (WalmartUsCatalogScore $row) => [
                'run_at' => $row->run_at,
                'listing_quality' => $row->listing_quality !== null ? (float) $row->listing_quality : null,
            ]);
    }

    /** Latest walmart_us_listing_audits row per SKU, joined with the item's product name. */
    public function currentAudits(): Collection
    {
        $latestIds = WalmartUsListingAudit::selectRaw('MAX(id) as id')->groupBy('sku')->pluck('id');

        $audits = WalmartUsListingAudit::whereIn('id', $latestIds)->get();
        $productNames = WalmartUsItem::whereIn('sku', $audits->pluck('sku'))->pluck('product_name', 'sku');
        $defectCounts = WalmartUsListingAuditIssue::whereIn('audit_id', $audits->pluck('id'))
            ->selectRaw('audit_id, COUNT(*) as cnt')
            ->groupBy('audit_id')
            ->pluck('cnt', 'audit_id');

        return $audits->map(fn (WalmartUsListingAudit $audit) => [
            'sku' => $audit->sku,
            'product_name' => $productNames[$audit->sku] ?? null,
            'as_of_date' => $audit->as_of_date,
            'listing_quality' => $audit->listing_quality !== null ? (float) $audit->listing_quality : null,
            'content_score' => $audit->content_score !== null ? (float) $audit->content_score : null,
            'offer_score' => $audit->offer_score !== null ? (float) $audit->offer_score : null,
            'rating_review_score' => $audit->rating_review_score !== null ? (float) $audit->rating_review_score : null,
            'price_score' => $audit->price_score !== null ? (float) $audit->price_score : null,
            'shipping_score' => $audit->shipping_score !== null ? (float) $audit->shipping_score : null,
            'transactibility_score' => $audit->transactibility_score !== null ? (float) $audit->transactibility_score : null,
            'issue_count' => $defectCounts[$audit->id] ?? 0,
        ])->sortBy('listing_quality')->values();
    }

    /**
     * @return Collection<int, array{code: string, occurrences: int}>
     */
    public function topIssuesByOccurrence(int $limit = 15): Collection
    {
        $latestAuditIds = WalmartUsListingAudit::selectRaw('MAX(id) as id')->groupBy('sku')->pluck('id');

        return WalmartUsListingAuditIssue::whereIn('audit_id', $latestAuditIds)
            ->selectRaw('code, COUNT(*) as occurrences')
            ->groupBy('code')
            ->orderByDesc('occurrences')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['code' => $row->code, 'occurrences' => (int) $row->occurrences]);
    }

    /** Latest audit + its issues for one SKU, null if it's never been audited. */
    public function auditForSku(string $sku): ?array
    {
        $audit = WalmartUsListingAudit::where('sku', $sku)->orderByDesc('id')->first();
        if ($audit === null) {
            return null;
        }

        return [
            'audit' => $audit,
            'product_name' => WalmartUsItem::where('sku', $sku)->value('product_name'),
            'issues' => WalmartUsListingAuditIssue::where('audit_id', $audit->id)->get(),
            'snapshot' => $audit->snapshot,
        ];
    }
}
