<?php

namespace App\Services\WalmartUs;

use RuntimeException;

/**
 * The LISTING_QUALITY async report cycle: request -> poll -> download.
 * This report reflects the PREVIOUS day's data (Walmart recalculates scores
 * daily) — callers must read the as-of date out of the downloaded payload
 * rather than assuming "today."
 *
 * Live-verified end to end against a real account (2026-09-03): request ->
 * RECEIVED -> INPROGRESS -> READY took ~10 minutes wall-clock, well past
 * the originally-assumed 300s default — bumped to a generous 30-minute
 * window (matching this app's own Data Kiosk polling precedent) with a
 * lighter poll cadence, both to accommodate that real latency and to avoid
 * needlessly burning this account's real (and fairly tight — confirmed hit
 * live during this same verification pass) rate limit. The READY response's
 * `payload` was an empty array with no separate `reportId` field, so the
 * requestId-as-reportId fallback below is exactly what real accounts hit,
 * not just a defensive guess.
 */
class WalmartUsReportService
{
    public function __construct(protected WalmartUsApiClient $client) {}

    /**
     * @return string decoded report content (caller parses per its own format)
     */
    public function fetchListingQualityReport(int $maxWaitSeconds = 1800, int $pollIntervalSeconds = 30): string
    {
        $create = $this->client->createListingQualityReportRequest();

        if ($create['status'] !== 200 && $create['status'] !== 202) {
            throw new RuntimeException('Walmart US createListingQualityReportRequest failed (status '.$create['status'].', correlation_id '.$create['correlation_id'].'): '.json_encode($create['body']));
        }

        $requestId = $create['body']['requestId'] ?? $create['body']['reportRequestId'] ?? null;
        if ($requestId === null) {
            throw new RuntimeException('Walmart US createListingQualityReportRequest did not return a requestId: '.json_encode($create['body']));
        }

        $elapsed = 0;
        $reportId = null;

        while ($elapsed < $maxWaitSeconds) {
            $status = $this->client->getReportRequestStatus($requestId);

            if ($status['status'] !== 200) {
                throw new RuntimeException('Walmart US getReportRequestStatus failed (status '.$status['status'].', correlation_id '.$status['correlation_id'].'): '.json_encode($status['body']));
            }

            $requestStatus = $status['body']['requestStatus'] ?? $status['body']['status'] ?? null;

            if ($requestStatus === 'COMPLETED' || $requestStatus === 'READY') {
                $reportId = $status['body']['reportId'] ?? $requestId;
                break;
            }

            if ($requestStatus === 'FAILED' || $requestStatus === 'ERROR') {
                throw new RuntimeException("Walmart US LISTING_QUALITY report request {$requestId} failed: ".json_encode($status['body']));
            }

            sleep($pollIntervalSeconds);
            $elapsed += $pollIntervalSeconds;
        }

        if ($reportId === null) {
            throw new RuntimeException("Walmart US LISTING_QUALITY report request {$requestId} did not complete within {$maxWaitSeconds}s.");
        }

        return $this->client->downloadReport($reportId);
    }
}
