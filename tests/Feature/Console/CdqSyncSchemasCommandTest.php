<?php

namespace Tests\Feature\Console;

use App\Models\CdqProductTypeSchema;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CdqSyncSchemasCommandTest extends TestCase
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

        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
        ]);
    }

    public function test_it_persists_required_and_recommended_attributes_derived_from_the_real_schema_shape(): void
    {
        Product::create(['asin' => 'B1TEST', 'sku' => 'SKU-1', 'type' => 'DRINK_FLAVORED', 'title' => 'Test', 'active' => true]);

        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/definitions/2020-09-01/productTypes/*' => Http::response([
                'schema' => ['link' => ['resource' => 'https://doc.test/schema.json']],
            ]),
            'doc.test/schema.json' => Http::response([
                'required' => ['brand', 'item_name'],
                'properties' => ['brand' => [], 'item_name' => [], 'bullet_point' => [], 'description' => []],
            ]),
        ]);

        $this->artisan('cdq:sync-schemas')->assertSuccessful();

        $row = CdqProductTypeSchema::where('product_type', 'DRINK_FLAVORED')->where('marketplace_id', 'A2EUQ1WTGCTBG2')->firstOrFail();
        $this->assertSame(['brand', 'item_name'], $row->required_json);
        // Flat rule: everything in properties minus required, no propertyGroups involved.
        $this->assertEqualsCanonicalizing(['bullet_point', 'description'], $row->recommended_json);
    }

    public function test_re_running_updates_the_same_row_instead_of_duplicating(): void
    {
        Product::create(['asin' => 'B1TEST', 'sku' => 'SKU-1', 'type' => 'DRINK_FLAVORED', 'title' => 'Test', 'active' => true]);

        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/definitions/2020-09-01/productTypes/*' => Http::response([
                'schema' => ['link' => ['resource' => 'https://doc.test/schema.json']],
            ]),
            'doc.test/schema.json' => Http::response(['required' => ['brand'], 'properties' => ['brand' => []]]),
        ]);

        $this->artisan('cdq:sync-schemas')->assertSuccessful();
        $this->artisan('cdq:sync-schemas')->assertSuccessful();

        $this->assertSame(1, CdqProductTypeSchema::where('product_type', 'DRINK_FLAVORED')->count());
    }

    public function test_a_product_type_amazon_does_not_recognize_fails_with_an_actionable_message_not_silently(): void
    {
        Product::create(['asin' => 'B1TEST', 'sku' => 'SKU-1', 'type' => 'BOGUS_TYPE', 'title' => 'Test', 'active' => true]);

        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/definitions/2020-09-01/productTypes/*' => Http::response(['errors' => [['code' => 'NOT_FOUND']]], 404),
        ]);

        $this->artisan('cdq:sync-schemas')->assertFailed();

        $this->assertSame(0, CdqProductTypeSchema::count());
    }
}
