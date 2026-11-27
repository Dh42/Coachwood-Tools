<?php

namespace Tests\Feature\Console;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CdqProbeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'id',
            'services.sp_api.client_secret' => 'secret',
            'services.sp_api.vendor_refresh_token' => 'vendor-token',
            'services.sp_api.vendor_code' => 'Z9999',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
        ]);

        Product::create(['asin' => 'B1TEST', 'sku' => 'SKU-1', 'type' => 'DRINK_FLAVORED', 'title' => 'Test Product', 'active' => true]);
    }

    public function test_probe_reports_every_operation_accessible_and_surfaces_the_property_groups_finding(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response([
                'asin' => 'B1TEST',
                'summaries' => [['itemName' => 'Test Product']],
                'relationships' => [['relationships' => [['parentAsins' => ['PARENT1'], 'type' => 'VARIATION']]]],
                'classifications' => [['classifications' => [['displayName' => 'Test Category']]]],
                'dimensions' => [['item' => ['height' => ['value' => 1]]]],
                'salesRanks' => [['ranks' => [['rank' => 100]]]],
            ]),
            'sp-api.test/definitions/2020-09-01/productTypes/*' => Http::response([
                'productType' => 'DRINK_FLAVORED',
                'schema' => ['link' => ['resource' => 'https://doc.test/schema.json']],
                'propertyGroups' => ['images' => ['title' => 'Images', 'propertyNames' => ['main_image']]],
            ]),
            'doc.test/schema.json' => Http::response(['required' => ['item_name', 'brand'], 'properties' => []]),
            'sp-api.test/aplus/2020-11-01/contentDocuments/*/asins*' => Http::response([
                'asinMetadataSet' => [['asin' => 'B1TEST']],
                'nextPageToken' => null,
            ]),
            'sp-api.test/aplus/2020-11-01/contentDocuments*' => Http::response([
                'contentMetadataRecords' => [
                    ['contentReferenceKey' => 'key1', 'contentMetadata' => ['status' => 'APPROVED', 'name' => 'Doc 1']],
                    ['contentReferenceKey' => 'key2', 'contentMetadata' => ['status' => 'DRAFT', 'name' => 'Doc 2']],
                ],
                'nextPageToken' => null,
            ]),
        ]);

        Artisan::call('cdq:probe');
        $output = Artisan::output();

        $this->assertStringContainsString('Vendor code resolution', $output);
        $this->assertStringContainsString('not needed', $output);
        $this->assertStringContainsString('Z9999', $output);
        // The core Phase 1 correction: propertyGroups is a display grouping, not an importance tier.
        $this->assertStringContainsString('not a Required/Recommended/Preferred tier', $output);
        $this->assertStringContainsString('2 documents found', $output);
        $this->assertStringContainsString('APPROVED: 1, DRAFT: 1', $output);
        $this->assertStringContainsString('1 ASIN(s) on one sampled document', $output);
    }

    public function test_probe_degrades_gracefully_when_a_role_is_denied_instead_of_throwing(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['errors' => [['code' => 'Unauthorized']]], 403),
            'sp-api.test/definitions/2020-09-01/productTypes/*' => Http::response(['errors' => [['code' => 'Unauthorized']]], 403),
            'sp-api.test/aplus/2020-11-01/contentDocuments*' => Http::response(['errors' => [['code' => 'Unauthorized']]], 403),
        ]);

        Artisan::call('cdq:probe');
        $output = Artisan::output();

        $this->assertStringContainsString('HTTP 403', $output);
        $this->assertStringNotContainsString('Exception', $output);
    }
}
