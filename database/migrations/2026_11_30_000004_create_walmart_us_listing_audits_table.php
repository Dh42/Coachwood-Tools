<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per SKU per audit run — append-only, never updated. as_of_date is
 * Walmart's own data date (the LISTING_QUALITY report reflects the
 * *previous* day, since Walmart recalculates daily), stored separately from
 * run_at (when we actually pulled it) so the two are never conflated.
 * our_rule_score stays null until Phase 2's rule engine exists — Phase 1
 * only ever writes Walmart's own scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walmart_us_listing_audits', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64);
            $table->date('as_of_date');
            $table->dateTime('run_at'); // groups every row from one command invocation
            $table->foreignId('snapshot_id')->nullable()->constrained('walmart_us_listing_snapshot')->nullOnDelete();

            $table->decimal('listing_quality', 5, 2)->nullable(); // Walmart's overall 0-100 score
            $table->decimal('content_score', 5, 2)->nullable();
            $table->decimal('offer_score', 5, 2)->nullable();
            $table->decimal('rating_review_score', 5, 2)->nullable();
            $table->decimal('price_score', 5, 2)->nullable();
            $table->decimal('shipping_score', 5, 2)->nullable();
            $table->decimal('transactibility_score', 5, 2)->nullable();
            $table->unsignedInteger('item_defect_cnt')->nullable();
            $table->decimal('defect_ratio', 6, 4)->nullable();

            $table->decimal('our_rule_score', 5, 2)->nullable(); // Phase 2

            $table->index(['sku', 'as_of_date']);
            $table->unique(['sku', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walmart_us_listing_audits');
    }
};
