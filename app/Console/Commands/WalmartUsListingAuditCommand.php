<?php

namespace App\Console\Commands;

use App\Services\WalmartUs\WalmartUsCatalogService;
use App\Services\WalmartUs\WalmartUsListingAuditService;
use Illuminate\Console\Command;

/**
 * Nightly ingest: syncs the catalog, then pulls Walmart's own Listing
 * Quality scores/issues and writes one snapshot + audit row per SKU.
 * Phase 1 only — no rule engine yet, `our_rule_score` stays null.
 */
class WalmartUsListingAuditCommand extends Command
{
    protected $signature = 'walmart-us:listing-audit {--sku=} {--dry-run}';

    protected $description = "Pulls the US Marketplace catalog and Walmart's own Listing Quality data, writing one dated audit snapshot per SKU";

    public function handle(WalmartUsCatalogService $catalogService, WalmartUsListingAuditService $auditService): int
    {
        $sku = $this->option('sku');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $itemCount = null;

            if ($sku === null && ! $dryRun) {
                $itemCount = $catalogService->syncItems();
                $this->info("Synced {$itemCount} catalog items.");
            }

            $result = $auditService->run($sku, $dryRun, $itemCount);
        } catch (\Throwable $e) {
            $this->error("Walmart US listing audit failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Walmart US listing audit complete (%s): %d SKUs processed, %d issues written, source=%s.',
            $dryRun ? 'dry-run — nothing written' : 'live',
            $result['skus_processed'],
            $result['issues_written'],
            $result['source'],
        ));

        return self::SUCCESS;
    }
}
