<?php

namespace App\Services\Amazon;

use App\Services\AmazonListings\ListingChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One SP-API client shared across every tool that talks to Amazon's Selling
 * Partner API — originally built for the Vendor/Seller Central listing
 * editors (hence most methods below still take a ListingChannel), now also
 * used by the PO subsystem (which is vendor-only, so those methods always
 * receive ListingChannel::Vendor). Every method takes an explicit
 * ListingChannel (or the specific identity/marketplace/refresh-token it
 * resolves to) rather than being duplicated per channel, unlike the legacy
 * PHP tools this replaces.
 *
 * Built on Laravel's Http facade (not raw curl) so it's fully Http::fake()
 * testable — a deliberate improvement over the Price Monitor migration's
 * scraper, which had to stay on curl for fidelity to a real browser fetch.
 */
class SpApiClient
{
    /**
     * Access token exchange, cached ~55 minutes (LWA tokens are valid ~60
     * min) keyed per refresh token. The legacy client re-exchanged on every
     * single call with no caching at all — a deliberate, approved
     * improvement here, not a behavior change to anything callers see.
     */
    public function getAccessToken(string $refreshToken): string
    {
        return Cache::remember('sp_api_access_token:'.md5($refreshToken), now()->addMinutes(55), function () use ($refreshToken) {
            $response = Http::asForm()->timeout(15)->post('https://api.amazon.com/auth/o2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => config('services.sp_api.client_id'),
                'client_secret' => config('services.sp_api.client_secret'),
            ]);

            $token = $response->json('access_token');
            if (! $response->successful() || ! $token) {
                throw new RuntimeException('SP-API token exchange failed: '.$response->body());
            }

            return $token;
        });
    }

    /**
     * @return array{status: int, body: ?array}
     */
    protected function request(string $method, string $path, array $query = [], ?array $jsonBody = null, ?string $refreshToken = null): array
    {
        $token = $this->getAccessToken($refreshToken ?? config('services.sp_api.vendor_refresh_token'));

        // SP-API's list convention: comma-join array query values instead of repeating the key.
        $query = array_map(fn ($v) => is_array($v) ? implode(',', $v) : $v, array_filter($query, fn ($v) => $v !== null));

        $response = Http::withHeaders([
            'x-amz-access-token' => $token,
            'Content-Type' => 'application/json',
        ])->timeout(20)->send($method, config('services.sp_api.base_url').$path, array_filter([
            'query' => $query,
            'json' => $jsonBody,
        ]));

        return ['status' => $response->status(), 'body' => $response->json()];
    }

    public function getCatalogItem(string $asin, ListingChannel $channel): array
    {
        $includedData = ['summaries', 'attributes', 'productTypes', 'images', 'identifiers'];
        if ($channel->includeVendorDetails()) {
            $includedData[] = 'vendorDetails';
        }

        return $this->request('GET', "/catalog/2022-04-01/items/{$asin}", [
            'marketplaceIds' => [$channel->marketplaceId()],
            'includedData' => $includedData,
        ], null, $channel->refreshToken());
    }

    /**
     * Same endpoint as getCatalogItem() above, with an explicit includedData
     * list instead of that method's fixed set — for probing which optional
     * Catalog Items data types (relationships, classifications, dimensions,
     * salesRanks) actually return something for this account, without
     * changing what the existing listing editor/audit requests on every
     * call. Not used by anything else; merge into getCatalogItem() if a
     * caller ends up needing more than the fixed set permanently.
     */
    public function getCatalogItemWithIncludedData(string $asin, ListingChannel $channel, array $includedData): array
    {
        return $this->request('GET', "/catalog/2022-04-01/items/{$asin}", [
            'marketplaceIds' => [$channel->marketplaceId()],
            'includedData' => $includedData,
        ], null, $channel->refreshToken());
    }

    /**
     * The true source of truth for editing — Catalog Items GET is a
     * filtered/smaller view that silently omits nutritional_info and
     * several other editable attributes (confirmed live).
     */
    public function getListingsItem(string $sku, ListingChannel $channel): array
    {
        return $this->request('GET', '/listings/2021-08-01/items/'.$channel->identityCode().'/'.rawurlencode($sku), [
            'marketplaceIds' => [$channel->marketplaceId()],
            'includedData' => ['attributes', 'summaries', 'issues'],
        ], null, $channel->refreshToken());
    }

    public function patchListing(string $sku, string $productType, array $patches, bool $preview, ListingChannel $channel): array
    {
        $query = ['marketplaceIds' => [$channel->marketplaceId()], 'includedData' => ['issues']];
        if ($preview) {
            $query['mode'] = 'VALIDATION_PREVIEW';
        }

        return $this->request('PATCH', '/listings/2021-08-01/items/'.$channel->identityCode().'/'.rawurlencode($sku), $query, [
            'productType' => $productType,
            'patches' => $patches,
        ], $channel->refreshToken());
    }

    public function getAplusPublishRecords(string $asin, ListingChannel $channel): array
    {
        return $this->request('GET', '/aplus/2020-11-01/contentPublishRecords', [
            'marketplaceId' => $channel->marketplaceId(),
            'asin' => $asin,
        ], null, $channel->refreshToken());
    }

    /**
     * The A+ Content Management API's own document listing — a different,
     * richer signal than getAplusPublishRecords() above (which only answers
     * "is something published for this ASIN", not module count/type).
     * Returns every A+ document on the account, not scoped to one ASIN;
     * pair with listContentDocumentAsinRelations() to find which ASINs a
     * given document actually covers. $pageToken paginates
     * (searchContentDocumentsResponse.nextPageToken); unverified against a
     * real catalog with more than one page as of when this was added.
     */
    public function searchContentDocuments(ListingChannel $channel, ?string $pageToken = null): array
    {
        return $this->request('GET', '/aplus/2020-11-01/contentDocuments', [
            'marketplaceId' => $channel->marketplaceId(),
            'pageToken' => $pageToken,
        ], null, $channel->refreshToken());
    }

    public function listContentDocumentAsinRelations(string $contentReferenceKey, ListingChannel $channel): array
    {
        return $this->request('GET', "/aplus/2020-11-01/contentDocuments/{$contentReferenceKey}/asins", [
            'marketplaceId' => $channel->marketplaceId(),
        ], null, $channel->refreshToken());
    }

    /**
     * includedDataSet is required by this operation (unlike the other two
     * A+ calls above) — CONTENTS is what carries contentModuleList (module
     * count, and each module's contentModuleType, e.g.
     * STANDARD_COMPARISON_TABLE), confirmed against Amazon's own JSON
     * schema model, not guessed.
     */
    public function getContentDocument(string $contentReferenceKey, ListingChannel $channel): array
    {
        return $this->request('GET', "/aplus/2020-11-01/contentDocuments/{$contentReferenceKey}", [
            'marketplaceId' => $channel->marketplaceId(),
            'includedDataSet' => ['CONTENTS'],
        ], null, $channel->refreshToken());
    }

    /**
     * A product type's schema is a static definition, not live listing data
     * — caching it avoids two extra live HTTP round trips (the definition
     * lookup plus the signed-URL document fetch) on every single row open,
     * which is real, user-visible latency this editor doesn't need to pay
     * more than once a day per product type.
     */
    public function getProductTypeSchema(string $productType, ListingChannel $channel): ?array
    {
        return Cache::remember(
            "sp_api_product_type_schema:{$channel->name}:{$productType}",
            now()->addDay(),
            function () use ($productType, $channel) {
                $result = $this->request('GET', "/definitions/2020-09-01/productTypes/{$productType}", [
                    'marketplaceIds' => [$channel->marketplaceId()],
                    'sellerId' => $channel->identityCode(),
                    'requirements' => 'LISTING',
                ], null, $channel->refreshToken());

                $url = $result['body']['schema']['link']['resource'] ?? null;
                if ($url === null) {
                    return null;
                }

                $response = Http::timeout(30)->get($url);

                return $response->successful() ? $response->json() : null;
            }
        );
    }

    /**
     * @param  array{reportOptions?: array, dataStartTime?: string, dataEndTime?: string}  $options  merged into the request body — e.g. the forecast engine's sales/inventory report pulls need reportOptions + a date range, which plain GET_MERCHANT_LISTINGS_ALL_DATA-style report types never did.
     */
    public function createReport(string $reportType, ListingChannel $channel, array $options = []): array
    {
        return $this->request('POST', '/reports/2021-06-30/reports', [], $options + [
            'reportType' => $reportType,
            'marketplaceIds' => [$channel->marketplaceId()],
        ], $channel->refreshToken());
    }

    public function getReport(string $reportId, ListingChannel $channel): array
    {
        return $this->request('GET', "/reports/2021-06-30/reports/{$reportId}", [], null, $channel->refreshToken());
    }

    /**
     * Wait budget for report pulls made by unattended cron jobs (vendor
     * sales sync, stock forecast, PO forecast, marketplace snapshot).
     * Amazon normally builds these in 2-3 minutes, but its report queue
     * periodically backs up and leaves one IN_PROGRESS past 10 minutes —
     * the old 600s budget failed stock-forecast:run 4 times in Aug-Sep 2026
     * and amazon:vendor-sales-sync every few days. Nobody is waiting on
     * these runs, so they wait it out instead of failing; retrying instead
     * would just re-join the same backed-up queue. Polled every 30s to keep
     * getReport calls down over the longer wait. Interactive callers (web
     * requests) keep the short default.
     */
    public const BATCH_REPORT_MAX_WAIT_SECONDS = 2700;

    public const BATCH_REPORT_POLL_INTERVAL_SECONDS = 30;

    public function waitForReport(string $reportId, ListingChannel $channel, int $maxWaitSeconds = 300, int $pollIntervalSeconds = 8): array
    {
        $elapsed = 0;
        while (true) {
            $result = $this->getReport($reportId, $channel);
            $status = $result['body']['processingStatus'] ?? null;

            if (in_array($status, ['DONE', 'CANCELLED', 'FATAL'], true)) {
                return $result;
            }

            if ($elapsed >= $maxWaitSeconds) {
                throw new RuntimeException("Report {$reportId} did not finish within {$maxWaitSeconds}s (last status: {$status}).");
            }

            sleep($pollIntervalSeconds);
            $elapsed += $pollIntervalSeconds;
        }
    }

    public function downloadReportDocument(string $reportDocumentId, ListingChannel $channel): string
    {
        $doc = $this->request('GET', "/reports/2021-06-30/documents/{$reportDocumentId}", [], null, $channel->refreshToken());

        $url = $doc['body']['url'] ?? null;
        if ($url === null) {
            throw new RuntimeException("Report document {$reportDocumentId} had no download URL.");
        }

        $content = Http::timeout(60)->get($url)->body();

        if (($doc['body']['compressionAlgorithm'] ?? null) === 'GZIP') {
            $content = gzdecode($content);
        }

        return $content;
    }

    /**
     * Vendor Orders API — GET /vendor/orders/v1/purchaseOrders. Always
     * vendor-scoped (there's no Seller Central purchase-order concept),
     * unlike every other method here — callers still pass
     * ListingChannel::Vendor explicitly, matching this class's existing
     * shape rather than special-casing this one method as channel-less.
     * Genuinely has no accepted/ASN/received/cancelled fulfillment funnel
     * in the response (confirmed against the legacy tool this replaces) —
     * callers should not expect those fields to ever be populated from
     * this endpoint.
     *
     * @param  array{createdAfter?: string, createdBefore?: string, purchaseOrderState?: string, limit?: int, nextToken?: string}  $query
     */
    public function getPurchaseOrders(array $query, ListingChannel $channel): array
    {
        return $this->request('GET', '/vendor/orders/v1/purchaseOrders', $query, null, $channel->refreshToken());
    }

    /**
     * Listings Restrictions API — GET /listings/2021-08-01/restrictions.
     * Amazon's own reference doc describes `sellerId` only as "a selling
     * partner identifier, such as a merchant account" — no vendor mention
     * anywhere in the docs or schema, unlike Listings Items' issues data.
     * Called anyway, at the user's explicit request, purely to see what
     * comes back for a vendor-scoped identity — if Amazon 400s or returns
     * nothing usable for vendors, that's an acceptable outcome, not a bug
     * to work around. ListingAuditService catches this independently, same
     * as every other call in auditOne(), so an unhelpful or failing
     * response here never blocks the rest of the audit.
     */
    public function getListingsRestrictions(string $asin, ListingChannel $channel): array
    {
        return $this->request('GET', '/listings/2021-08-01/restrictions', [
            'asin' => $asin,
            'sellerId' => $channel->identityCode(),
            'marketplaceIds' => [$channel->marketplaceId()],
        ], null, $channel->refreshToken());
    }

    /**
     * Data Kiosk (GraphQL async submit/poll/download) — a genuinely
     * different SP-API family from the Reports API methods above, used by
     * Geo Sales Intelligence and (later) Net Realized Price. Same
     * request()/token/channel plumbing, different paths and response
     * shapes: a completed query's document id is `dataDocumentId` (not
     * `reportDocumentId`), the document GET returns `documentUrl` (not
     * `url`), and there's no `compressionAlgorithm` flag to check — the
     * payload is unconditionally gzipped, confirmed live.
     */
    public function submitDataKioskQuery(string $gql, ListingChannel $channel): string
    {
        $create = $this->request('POST', '/dataKiosk/2023-11-15/queries', [], ['query' => $gql], $channel->refreshToken());

        $queryId = $create['body']['queryId'] ?? null;
        if ($queryId === null) {
            throw new RuntimeException('Data Kiosk createQuery did not return a queryId: '.json_encode($create['body'] ?? null));
        }

        return $queryId;
    }

    /**
     * Retries on a transient error with backoff rather than failing
     * outright — Data Kiosk's real rate limits aren't published, so this
     * defends empirically (matches the legacy client's approach) instead
     * of assuming a specific number.
     */
    public function pollDataKioskQuery(string $queryId, ListingChannel $channel, int $maxWaitSeconds = 1800, int $pollIntervalSeconds = 15): array
    {
        $elapsed = 0;
        $consecutiveErrors = 0;

        while ($elapsed < $maxWaitSeconds) {
            try {
                $result = $this->request('GET', "/dataKiosk/2023-11-15/queries/{$queryId}", [], null, $channel->refreshToken());
                $consecutiveErrors = 0;
            } catch (\Throwable $e) {
                $consecutiveErrors++;
                if ($consecutiveErrors >= 5) {
                    throw $e;
                }
                $backoff = $pollIntervalSeconds * $consecutiveErrors;
                sleep($backoff);
                $elapsed += $backoff;

                continue;
            }

            $status = $result['body']['processingStatus'] ?? null;
            if (in_array($status, ['DONE', 'FATAL', 'CANCELLED'], true)) {
                return $result;
            }

            sleep($pollIntervalSeconds);
            $elapsed += $pollIntervalSeconds;
        }

        throw new RuntimeException("Data Kiosk query {$queryId} did not finish within {$maxWaitSeconds}s.");
    }

    public function downloadDataKioskDocument(string $documentId, ListingChannel $channel): string
    {
        $doc = $this->request('GET', "/dataKiosk/2023-11-15/documents/{$documentId}", [], null, $channel->refreshToken());

        $url = $doc['body']['documentUrl'] ?? null;
        if ($url === null) {
            throw new RuntimeException("Data Kiosk document {$documentId} had no documentUrl.");
        }

        $raw = Http::timeout(120)->get($url)->body();
        $decoded = @gzdecode($raw);

        return $decoded !== false ? $decoded : $raw;
    }

    /**
     * Replenishment API (2022-11-07), listOfferMetrics — Subscribe & Save
     * program metrics per ASIN. Confirmed against Amazon's own JSON schema
     * (not just the prose docs) that the operation itself supports vendors
     * (productGroup is explicitly vendor-only in the response, alongside
     * sku/fulfillmentChannelType being seller-only) — only the FORECAST
     * timePeriodType is seller-only, so this always requests PERFORMANCE.
     * Requires the SP-API app's refresh token to have been authorized with
     * the Replenishment role; an app authorized before that role existed
     * will get a 403 here until re-authorized in Vendor/Seller Central —
     * not something this client can detect or work around.
     *
     * Up to 20 ASINs per call (Amazon's own limit) and a tight 1
     * request/second rate limit with burst 1 — callers doing more than one
     * batch must pace themselves; this method makes no attempt to.
     *
     * @param  array<string>  $asins  1-20 ASINs
     */
    public function getReplenishmentOfferMetrics(array $asins, string $periodStart, string $periodEnd, ListingChannel $channel): array
    {
        return $this->request('POST', '/replenishment/2022-11-07/offers/metrics/search', [], [
            'pagination' => ['limit' => 20, 'offset' => 0],
            'filters' => [
                'timeInterval' => ['startDate' => $periodStart, 'endDate' => $periodEnd],
                'timePeriodType' => 'PERFORMANCE',
                'programTypes' => ['SUBSCRIBE_AND_SAVE'],
                'aggregationFrequency' => 'WEEK',
                'marketplaceId' => $channel->marketplaceId(),
                'asins' => $asins,
            ],
        ], $channel->refreshToken());
    }

    /**
     * Customer Feedback API — a genuinely different SP-API family again:
     * not Data Kiosk (confirmed live: Amazon.ca isn't in this API's own
     * marketplaceId enum at all, so Data Kiosk's customerSatisfaction
     * domain is structurally CA-blocked for us), not the Reports API — a
     * plain synchronous GET, no async submit/poll/download. US marketplace
     * only, but the data itself is brand-level (identical regardless of
     * which of our two SP-API accounts asks, confirmed live), so this
     * always uses ListingChannel::Seller for its US marketplace ID and
     * refresh token, even for VC-only ASINs — there is no per-channel
     * variance to parameterize here.
     *
     * @return array{status: int, body: ?array}
     */
    public function getCustomerFeedbackTopics(string $asin): array
    {
        return $this->request('GET', "/customerFeedback/2024-06-01/items/{$asin}/reviews/topics", [
            'marketplaceId' => ListingChannel::Seller->marketplaceId(),
            'sortBy' => 'MENTIONS',
        ], null, ListingChannel::Seller->refreshToken());
    }

    /**
     * getItemReviewTrends — a SEPARATE endpoint/call from
     * getCustomerFeedbackTopics(), no sortBy param (confirmed from the
     * actual spec: only asin + marketplaceId). Six months of monthly
     * occurrencePercentage per topic, no mention counts at this grain.
     *
     * @return array{status: int, body: ?array}
     */
    public function getCustomerFeedbackTrends(string $asin): array
    {
        return $this->request('GET', "/customerFeedback/2024-06-01/items/{$asin}/reviews/trends", [
            'marketplaceId' => ListingChannel::Seller->marketplaceId(),
        ], null, ListingChannel::Seller->refreshToken());
    }
}
