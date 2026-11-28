<?php

namespace Tests\Unit\Amazon\Probe;

use App\Amazon\Probe\ProbeClient;
use App\Services\AmazonListings\ListingChannel;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class ProbeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.vendor_refresh_token' => 'vendor-refresh-token',
            'services.sp_api.vendor_code' => 'Z0000',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
        ]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake-token']),
            'sp-api.test/*' => Http::response([], 202),
        ]);
    }

    public function test_reads_and_the_two_create_calls_are_allowed(): void
    {
        $client = new ProbeClient;

        $this->assertSame(202, $client->call('GET', '/reports/2021-06-30/reports/1')['status']);
        $this->assertSame(202, $client->call('POST', '/reports/2021-06-30/reports', [], ['reportType' => 'X'])['status']);
        $this->assertSame(202, $client->call('POST', '/dataKiosk/2023-11-15/queries', [], ['query' => '{}'])['status']);
    }

    public function test_a_listing_submission_is_refused_before_anything_is_sent(): void
    {
        // With writes off, SpApiClient::patchListing refuses a real PATCH
        // itself (step 11). The probe must refuse even with writes on.
        config(['tools.external_writes' => true]);

        try {
            (new ProbeClient)->patchListing('SKU-1', 'DRINK_FLAVORED', [['op' => 'replace']], false, ListingChannel::Vendor);
            $this->fail('Expected a LogicException.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_other_writes_are_refused(): void
    {
        foreach ([['POST', '/vendor/orders/v1/acknowledgements'], ['PUT', '/listings/2021-08-01/items/Z0000/SKU'], ['DELETE', '/reports/2021-06-30/reports/1']] as [$method, $path]) {
            try {
                (new ProbeClient)->call($method, $path);
                $this->fail("Expected {$method} {$path} to be refused.");
            } catch (LogicException) {
                // refused
            }
        }

        Http::assertNothingSent();
    }
}
