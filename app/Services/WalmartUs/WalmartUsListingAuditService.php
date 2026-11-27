<?php

namespace App\Services\WalmartUs;

use App\Models\WalmartUsCatalogScore;
use App\Models\WalmartUsListingAudit;
use App\Models\WalmartUsListingAuditIssue;
use App\Models\WalmartUsListingSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one Phase 1 audit run: pull Walmart's own Listing Quality
 * data (report path, falling back to the Insights endpoints), snapshot the
 * raw payload, and write one audit + issue rows per SKU plus one
 * catalog-level rollup row. `our_rule_score` stays null throughout — the
 * rule engine that fills it in is Phase 2.
 */
class WalmartUsListingAuditService
{
    public function __construct(
        protected WalmartUsApiClient $client,
        protected WalmartUsReportService $reportService,
    ) {}

    /**
     * @param  ?int  $itemCount  Total catalog item count, if already known
     *                           from a preceding WalmartUsCatalogService::syncItems()
     *                           call this run — the Listing Quality score
     *                           endpoint has no item-count field of its own
     *                           (confirmed live), so this is the only real
     *                           source for walmart_us_catalog_scores.item_count.
     * @return array{skus_processed: int, issues_written: int, source: string, dry_run: bool}
     */
    public function run(?string $sku = null, bool $dryRun = false, ?int $itemCount = null): array
    {
        $rows = $this->pullListingQualityRows($sku);

        $skusProcessed = 0;
        $issuesWritten = 0;

        foreach ($rows as $row) {
            $rowSku = $row['sku'] ?? null;
            if ($rowSku === null || ($sku !== null && $rowSku !== $sku)) {
                continue;
            }

            $issuesWritten += $this->writeAuditRow($row, $dryRun);
            $skusProcessed++;
        }

        $this->writeCatalogScoreRow($dryRun, $itemCount);

        return [
            'skus_processed' => $skusProcessed,
            'issues_written' => $issuesWritten,
            'source' => $rows->source,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Report-path parsing (`$decoded['items'] ?? $decoded`) is STILL A
     * GUESS — a real report reached READY during live verification
     * (2026-09-03), but its download consistently 520-errored ("Pls try
     * again after sometime") across many real attempts — a materially
     * different, more specific error than the clean 404s every genuinely
     * wrong path/endpoint produced, so the download path is very likely
     * structurally correct but something else (transient backend issue, or
     * this account's report wasn't actually servable despite READY status)
     * blocked confirming the actual downloaded content's shape. Needs one
     * more live pass. The Insights fallback path below IS fully confirmed
     * live and correctly normalized via normalizeInsightsRow() — see that
     * method's docblock for exactly what's mapped vs. still approximate.
     *
     * @return Collection<int, array> with a ->source string tacked on for logging
     */
    protected function pullListingQualityRows(?string $sku): Collection
    {
        try {
            $raw = $this->reportService->fetchListingQualityReport();
            $decoded = json_decode($raw, true);
            $rows = collect($decoded['items'] ?? $decoded ?? []);
            // A report with no SKU rows (an error body that decoded fine)
            // falls back to Insights too, instead of auditing 0 SKUs
            // (found issue 11).
            if ($rows->filter(fn ($row) => is_array($row) && isset($row['sku']))->isEmpty()) {
                throw new \RuntimeException('Walmart US LISTING_QUALITY report had no SKU rows.');
            }
            $rows->source = 'report';

            return $rows;
        } catch (Throwable $e) {
            Log::warning('Walmart US LISTING_QUALITY report path failed, falling back to Insights endpoints.', [
                'error' => $e->getMessage(),
            ]);

            $result = $this->client->getListingQualityItems($sku !== null ? [$sku] : []);
            $rows = collect($result['body']['payload'] ?? [])->map(fn (array $row) => $this->normalizeInsightsRow($row));
            $rows->source = 'insights';

            return $rows;
        }
    }

    /**
     * Maps the Insights `items` endpoint's real (confirmed live,
     * 2026-09-03) per-item shape onto the same flat row shape
     * writeAuditRow() expects from the report path. Real shape is deeply
     * nested: `sku` sits alongside `productId`/`itemId`/`qualityScoreData`/
     * `scoreDetails`/`postPurchaseIssues` at the row's top level — NOT
     * missing, just far from the flat structure originally guessed.
     *
     * Confirmed/mapped: `sku`; the overall score (`qualityScoreData.score`)
     * -> listingQuality; the six `qualityScoreData.values[]` category
     * scores (matched by their real `scoreType` label strings) -> our six
     * score columns; `postPurchaseIssues` (a plain int) -> itemDefectCnt.
     * No `defectRatio` or `asOfDate` field exists on this endpoint at all —
     * unlike the report, this endpoint appears to reflect live/current
     * data, not a lagged daily snapshot, so `asOfDate` defaults to today.
     *
     * Best-effort, NOT fully confirmed: the issues[] extraction below only
     * covers the two categories observed with real non-empty issue detail
     * in this account's live data (contentAndDiscoverability's per-attribute
     * issues, and offer.price/offer.shippingSpeed's issueTitle) — every
     * item in the live sample had postPurchaseIssues=0 and empty
     * ratingReviews issue detail, so those paths are unexercised and may
     * need a follow-up pass once an item with real defects/rating issues
     * is available to inspect.
     */
    protected function normalizeInsightsRow(array $row): array
    {
        $scoreByType = collect($row['qualityScoreData']['values'] ?? [])
            ->pluck('scoreValue', 'scoreType');

        $issues = [];

        foreach ($row['scoreDetails']['contentAndDiscoverability']['issues'] ?? [] as $attribute) {
            foreach ($attribute['issues'] ?? [] as $issue) {
                $issues[] = [
                    'code' => $issue['title'] ?? 'CONTENT_ISSUE',
                    'field' => $attribute['attributeName'] ?? null,
                    'message' => $issue['value'] ?? null,
                ];
            }
        }

        foreach (['price', 'shippingSpeed'] as $offerField) {
            $issueTitle = $row['scoreDetails']['offer'][$offerField]['issueTitle'] ?? null;
            if ($issueTitle !== null) {
                $issues[] = [
                    'code' => strtoupper(str_replace(' ', '_', $issueTitle)),
                    'field' => $offerField,
                    'message' => $issueTitle,
                ];
            }
        }

        return [
            'sku' => $row['sku'] ?? null,
            'wpid' => null,
            'asOfDate' => Carbon::now()->toDateString(),
            'listingQuality' => $row['qualityScoreData']['score'] ?? null,
            'contentScore' => $scoreByType['content & Discoverability'] ?? null,
            'offerScore' => $scoreByType['offer'] ?? null,
            'ratingReviewScore' => $scoreByType['Rating & Reviews'] ?? null,
            'priceScore' => $scoreByType['Price competitiveness'] ?? null,
            'shippingScore' => $scoreByType['Shipping speed'] ?? null,
            'transactibilityScore' => $scoreByType['Published and in stock'] ?? null,
            'itemDefectCnt' => $row['postPurchaseIssues'] ?? null,
            'defectRatio' => null,
            'issues' => $issues,
        ];
    }

    protected function writeAuditRow(array $row, bool $dryRun): int
    {
        $sku = (string) $row['sku'];
        $asOfDate = isset($row['asOfDate']) ? Carbon::parse($row['asOfDate']) : Carbon::yesterday();
        $issues = $row['issues'] ?? [];

        if ($dryRun) {
            Log::info('Walmart US listing audit dry-run', ['sku' => $sku, 'as_of_date' => $asOfDate->toDateString(), 'issue_count' => count($issues)]);

            return count($issues);
        }

        return DB::transaction(function () use ($row, $sku, $asOfDate, $issues) {
            $itemJson = json_encode($row);
            $contentHash = hash('sha256', $itemJson);

            $snapshot = WalmartUsListingSnapshot::firstOrCreate(
                ['sku' => $sku, 'content_hash' => $contentHash],
                [
                    'wpid' => $row['wpid'] ?? null,
                    'item_json' => $itemJson,
                    'listing_quality_json' => json_encode($issues),
                    'captured_at' => Carbon::now(),
                ]
            );

            $audit = WalmartUsListingAudit::create([
                'sku' => $sku,
                'run_at' => Carbon::now(),
                'as_of_date' => $asOfDate->toDateString(),
                'snapshot_id' => $snapshot->id,
                'listing_quality' => $row['listingQuality'] ?? null,
                'content_score' => $row['contentScore'] ?? null,
                'offer_score' => $row['offerScore'] ?? null,
                'rating_review_score' => $row['ratingReviewScore'] ?? null,
                'price_score' => $row['priceScore'] ?? null,
                'shipping_score' => $row['shippingScore'] ?? null,
                'transactibility_score' => $row['transactibilityScore'] ?? null,
                'item_defect_cnt' => $row['itemDefectCnt'] ?? null,
                'defect_ratio' => $row['defectRatio'] ?? null,
            ]);

            foreach ($issues as $issue) {
                WalmartUsListingAuditIssue::create([
                    'audit_id' => $audit->id,
                    'source' => 'walmart',
                    'code' => $issue['code'] ?? 'UNKNOWN',
                    'severity' => $issue['severity'] ?? null,
                    'field' => $issue['field'] ?? null,
                    'message' => $issue['message'] ?? null,
                    'recommended_action' => $issue['recommendedAction'] ?? null,
                ]);
            }

            return count($issues);
        });
    }

    /**
     * Confirmed live: the score endpoint's real shape is nested under
     * `payload` — `payload.listingQuality`, `payload.score.*`,
     * `payload.postPurchaseQuality.*` — NOT flat top-level fields as
     * originally assumed from the brief's prose. There is no item-count
     * field anywhere in this payload; $itemCount must come from the caller.
     */
    protected function writeCatalogScoreRow(bool $dryRun, ?int $itemCount): void
    {
        $result = $this->client->getListingQualityScore();

        if ($result['status'] !== 200) {
            Log::warning('Walmart US catalog-level getListingQualityScore failed — skipping catalog_scores row.', [
                'status' => $result['status'],
                'correlation_id' => $result['correlation_id'],
            ]);

            return;
        }

        $payload = $result['body']['payload'] ?? [];
        $score = $payload['score'] ?? [];
        $postPurchaseQuality = $payload['postPurchaseQuality'] ?? [];

        if ($dryRun) {
            Log::info('Walmart US catalog score dry-run', $payload);

            return;
        }

        WalmartUsCatalogScore::create([
            'run_at' => Carbon::now(),
            'listing_quality' => $payload['listingQuality'] ?? null,
            'content_score' => $score['contentScore'] ?? null,
            'offer_score' => $score['offerScore'] ?? null,
            'rating_review_score' => $score['ratingReviewScore'] ?? null,
            'price_score' => $score['priceScore'] ?? null,
            'shipping_score' => $score['shippingScore'] ?? null,
            'transactibility_score' => $score['transactibilityScore'] ?? null,
            'item_defect_cnt' => $postPurchaseQuality['itemDefectCnt'] ?? null,
            'defect_ratio' => $postPurchaseQuality['defectRatio'] ?? null,
            'item_count' => $itemCount,
        ]);
    }
}
