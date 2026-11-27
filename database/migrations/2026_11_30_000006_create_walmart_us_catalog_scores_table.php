<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per audit run — the catalog-level rollup from
 * GET /insights/items/listingQuality/score, append-only alongside
 * walmart_us_listing_audits (same run_at grouping key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walmart_us_catalog_scores', function (Blueprint $table) {
            $table->id();
            $table->dateTime('run_at');
            $table->decimal('listing_quality', 5, 2)->nullable();
            $table->decimal('content_score', 5, 2)->nullable();
            $table->decimal('offer_score', 5, 2)->nullable();
            $table->decimal('rating_review_score', 5, 2)->nullable();
            $table->decimal('price_score', 5, 2)->nullable();
            $table->decimal('shipping_score', 5, 2)->nullable();
            $table->decimal('transactibility_score', 5, 2)->nullable();
            $table->unsignedInteger('item_defect_cnt')->nullable();
            $table->decimal('defect_ratio', 6, 4)->nullable();
            $table->unsignedInteger('item_count')->nullable();

            $table->index('run_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walmart_us_catalog_scores');
    }
};
