<?php

namespace Tests\Feature\Console;

use App\Models\ListingAudit;
use App\Models\Product;
use App\Models\ScListingAudit;
use App\Models\ScProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingsRepairDoubleEncodedAuditedAttributesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repairs_double_encoded_rows_on_both_channels_and_leaves_correct_rows_alone(): void
    {
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        $scProduct = ScProduct::create(['asin' => 'B1', 'sku' => 'SKU-A', 'title' => 'T', 'status' => 'Active', 'synced_at' => now()]);

        $good = ['bullet_point' => ['kind' => 'count', 'value' => 2]];
        $goodAudit = ListingAudit::create(['product_id' => $product->id, 'audited_attributes' => $good, 'checked_at' => now()]);
        $goodScAudit = ScListingAudit::create(['product_id' => $scProduct->id, 'audited_attributes' => $good, 'checked_at' => now()]);

        // Simulate the double-encoding bug directly at the DB layer — a JSON
        // string handed to the array-cast setter, which json_encodes it
        // again — the same shape auditOne() used to write before the fix.
        $brokenAudit = ListingAudit::create(['product_id' => $product->id, 'audited_attributes' => null, 'checked_at' => now()]);
        DB::table('listing_audit')->where('id', $brokenAudit->id)->update(['audited_attributes' => json_encode(json_encode($good))]);

        $brokenScAudit = ScListingAudit::create(['product_id' => $scProduct->id, 'audited_attributes' => null, 'checked_at' => now()]);
        DB::table('sc_listing_audit')->where('id', $brokenScAudit->id)->update(['audited_attributes' => json_encode(json_encode($good))]);

        $this->artisan('listings:repair-double-encoded-audited-attributes')->assertSuccessful();

        $this->assertSame($good, $goodAudit->fresh()->audited_attributes); // untouched
        $this->assertSame($good, $goodScAudit->fresh()->audited_attributes); // untouched
        $this->assertSame($good, $brokenAudit->fresh()->audited_attributes); // repaired
        $this->assertSame($good, $brokenScAudit->fresh()->audited_attributes); // repaired
    }
}
