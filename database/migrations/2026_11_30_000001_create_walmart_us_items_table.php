<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Current catalog state per SKU — upserted on every catalog ingest, not
 * append-only (same "_current" shape as vc_traffic_current/nrp_cost_current
 * — history lives in walmart_us_listing_snapshot instead, keyed the same way).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walmart_us_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('wpid', 32)->nullable(); // Walmart Item ID
            $table->string('gtin', 32)->nullable();
            $table->string('product_name', 500)->nullable();
            $table->string('publish_status', 32)->nullable();
            $table->string('lifecycle_status', 32)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            // Our own SKU, when it differs from what we submit as the Walmart
            // seller SKU — nullable since the mapping may not exist for every
            // item (a brand-new Walmart-only listing, for instance).
            $table->string('internal_sku', 64)->nullable();
            $table->dateTime('last_synced_at')->nullable();

            $table->index('internal_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walmart_us_items');
    }
};
