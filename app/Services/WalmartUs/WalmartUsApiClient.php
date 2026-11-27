<?php

namespace App\Services\WalmartUs;

use App\Services\Walmart\Concerns\InteractsWithWalmartApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Walmart Marketplace (3P Seller Center) API client for the US market.
 * Thin wrapper over InteractsWithWalmartApi's token/header/retry plumbing —
 * one method per endpoint this tool's Phase 1 ingest actually calls.
 *
 * Live-verified against a real US Marketplace account (2026-09-03) after
 * Walmart's real developer docs turned out to be gated behind a partner
 * login. Corrections made from that pass are called out per method below;
 * anything not explicitly marked "confirmed live" is still built from the
 * brief's documented shape only.
 */
class WalmartUsApiClient
{
    use InteractsWithWalmartApi;

    protected function configKey(): string
    {
        return 'us';
    }

    /**
     * Paged catalog/item pull — confirmed live as plain offset/limit
     * pagination (`totalItems` in the response, no cursor ever returned
     * despite `nextCursor` being a recognized-but-separate query param on
     * this same endpoint — unconfirmed what that variant is for, unused
     * here). Response root key is `ItemResponse`.
     *
     * @param  array{offset?: int, limit?: int}  $query
     * @return array{status: int, body: ?array, correlation_id: string}
     */
    public function getItems(array $query = []): array
    {
        return $this->request('GET', '/items', $query);
    }

    /**
     * Cheap reconciliation check — confirmed live: requires a `status`
     * query param (a bare call 400s with "mandatory parameter(status) is
     * missing"), returns a flat `{"PUBLISHED": 26}`-style map.
     *
     * @return array{status: int, body: ?array, correlation_id: string}
     */
    public function getItemCountByStatus(string $status): array
    {
        return $this->request('GET', '/items/count', ['status' => $status]);
    }

    /**
     * Catalog-level Listing Quality rollup — confirmed live. Real shape is
     * nested: `{"payload": {"listingQuality": .., "score": {"contentScore":
     * .., "offerScore": .., ...}, "postPurchaseQuality": {"itemDefectCnt":
     * .., "defectRatio": ..}}, "status": "OK"}` — NOT the flat top-level
     * fields the brief's prose suggested. No item-count field anywhere in
     * this payload; callers needing a catalog item count must get it from
     * getItems()'s `totalItems` instead.
     *
     * @return array{status: int, body: ?array, correlation_id: string}
     */
    public function getListingQualityScore(array $query = []): array
    {
        return $this->request('GET', '/insights/items/listingQuality/score', $query);
    }

    /**
     * Per-item Listing Quality detail (scores + issues), the Insights
     * fallback path when the async LISTING_QUALITY report isn't used.
     * Confirmed live: POST with a `{"skus": [...]}` body succeeds (200) and
     * returns `{"payload": [...], "totalItems": N, "nextCursor": ...}`, BUT
     * the `skus` filter does not actually narrow the result server-side — a
     * single-SKU request still returned the full paginated catalog.
     * Callers must filter client-side on each row's own `sku` field, which
     * WalmartUsListingAuditService::run() already does. Each row's real
     * shape is deeply nested (`qualityScoreData.score`/`.values[]`,
     * `scoreDetails.{offer,contentAndDiscoverability,ratingReviews}`,
     * `postPurchaseIssues`) but DOES include a plain top-level `sku` field —
     * confirmed and fully mapped by
     * WalmartUsListingAuditService::normalizeInsightsRow(), see its
     * docblock for exactly what's covered vs. still best-effort (the
     * issues[] extraction specifically, since this account's live data had
     * no non-empty defect/rating-issue examples to verify against).
     *
     * @param  array<string>  $skus
     * @return array{status: int, body: ?array, correlation_id: string}
     */
    public function getListingQualityItems(array $skus = []): array
    {
        return $this->request('POST', '/insights/items/listingQuality/items', [], [
            'skus' => $skus,
        ]);
    }

    /**
     * GET /insights/items/listingQuality/count — confirmed live, but despite
     * the name this is NOT a defect count: it returns offer counts grouped
     * by category/productType (`{"payload": [{"category": .., "offerCount":
     * .., "productType": [...]}, ...]}`). Not called by Phase 1's
     * orchestration; kept for Phase 2's rule engine to use for
     * category-level breakdowns.
     *
     * @return array{status: int, body: ?array, correlation_id: string}
     */
    public function getListingQualityCategoryCounts(array $query = []): array
    {
        return $this->request('GET', '/insights/items/listingQuality/count', $query);
    }

    /**
     * Requests an async LISTING_QUALITY bulk report. Reflects the PREVIOUS
     * day's data — Walmart recalculates scores daily — so the report's own
     * as-of date must be read from the downloaded payload, never assumed to
     * be "today." Confirmed live: needs reportType/reportVersion as query
     * params AND an explicit `{}` JSON body — a bodyless POST 415s. Status
     * transitions confirmed live: RECEIVED -> INPROGRESS -> (terminal state
     * unconfirmed — still INPROGRESS after ~13 minutes of polling in this
     * account's real run, longer than initially assumed; see
     * WalmartUsReportService's generous poll window).
     *
     * @return array{status: int, body: ?array, correlation_id: string}
     */
    public function createListingQualityReportRequest(): array
    {
        return $this->request('POST', '/reports/reportRequests', [
            'reportType' => 'LISTING_QUALITY',
            'reportVersion' => 'v1',
        ], []);
    }

    /**
     * @return array{status: int, body: ?array, correlation_id: string}
     */
    public function getReportRequestStatus(string $requestId): array
    {
        return $this->request('GET', "/reports/reportRequests/{$requestId}");
    }

    /**
     * Downloads a completed report's content. STILL NOT FULLY CONFIRMED,
     * despite two separate live passes: a real report did reach READY, and
     * `/reports/reports/{id}` (the original guess) is confirmed WRONG
     * (clean 404). `/reportRequests/{id}/download` is the strongest
     * remaining candidate — a bodyless-Accept-header attempt got a specific
     * `INVALID_ACCEPT_TYPE` error naming this exact path as needing
     * `Accept: application/json` (now added below, confirming the path
     * itself is real), but with that header present it then consistently
     * returns `520 SYSTEM_ERROR "Pls try again after sometime"` — reproduced
     * ~8 times across two separate sessions and multiple minutes apart, not
     * a one-off transient blip. Several sibling path/param variants were
     * also tried (`?requestId=`, `?requestIds=`, appending reportType/
     * reportVersion query params) — all either clean-404'd or hit a
     * differently-shaped 400, none succeeded. Root cause unconfirmed: could
     * be this specific report's file never actually finished landing
     * despite its status reading READY, or a genuine account/backend issue
     * — worth escalating to Walmart support with a logged correlation ID
     * if it reproduces again on a fresh report. Do not trust this beyond
     * `--dry-run` until an actual 200 download is observed.
     */
    public function downloadReport(string $reportId): string
    {
        $cfg = $this->walmartConfig();

        $response = Http::withHeaders([
            'WM_SEC.ACCESS_TOKEN' => $this->accessToken(),
            'WM_SVC.NAME' => 'Walmart Marketplace',
            'WM_QOS.CORRELATION_ID' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->timeout(60)->get(rtrim((string) $cfg['base_url'], '/')."/reports/reportRequests/{$reportId}/download");

        // Found issue 11: the status used to be ignored, so the 520 error
        // body above was read as an empty report.
        if (! $response->successful()) {
            throw new RuntimeException("Walmart US report download failed (status {$response->status()}): ".Str::limit($response->body(), 300));
        }

        return $response->body();
    }
}
