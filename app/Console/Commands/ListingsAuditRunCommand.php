<?php

namespace App\Console\Commands;

use App\Services\AmazonListings\ListingAuditService;
use App\Services\AmazonListings\ListingChannel;
use Illuminate\Console\Command;

/**
 * Daily standing job wrapping the same ListingAuditService::auditAll() the
 * "Audit Listings" button already calls synchronously from the web UI —
 * this just means the LI column (and everything else the audit populates:
 * A+ status, image count, contribution drift) is never more than a day
 * stale even if nobody remembers to click the button. Runs both channels;
 * CLI has no execution-time-limit concern the way the web controller's
 * set_time_limit(600) fallback does, so this can simply take as long as it
 * takes for the full catalog.
 */
class ListingsAuditRunCommand extends Command
{
    protected $signature = 'listings:audit-run';

    protected $description = 'Runs the full Listings Audit (issues, A+ status, image count, contribution drift) for every active Vendor and Seller Central product';

    public function handle(ListingAuditService $service): int
    {
        foreach ([ListingChannel::Vendor, ListingChannel::Seller] as $channel) {
            $this->info("Auditing {$channel->value} listings...");
            $result = $service->auditAll($channel);
            $this->info("  Checked {$result['checked']}, failed {$result['failed']}.");
        }

        return self::SUCCESS;
    }
}
