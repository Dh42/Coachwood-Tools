<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned thresholds/weights for our own rule layer (Phase 2 — nothing
 * reads this table yet in Phase 1). Same convention as every other params
 * table in this app: plain effective_from, no updated_at, insert-a-new-row-
 * never-update. Matches PpmParams/NrpParams' shape (one wide row per
 * version) rather than CDQ's check_config_version+check_config pair — the
 * brief explicitly names PpmParams/NrpParams as the pattern to follow here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walmart_us_audit_params', function (Blueprint $table) {
            $table->id();
            $table->dateTime('effective_from');

            $table->unsignedSmallInteger('min_title_length')->nullable();
            $table->unsignedSmallInteger('max_title_length')->nullable();
            $table->unsignedTinyInteger('min_image_count')->nullable();
            $table->unsignedSmallInteger('min_image_resolution_px')->nullable();
            $table->unsignedSmallInteger('min_description_length')->nullable();
            $table->unsignedTinyInteger('min_bullet_count')->nullable();
            // Allowed price deviation from our own guardrail price, as a
            // fraction (e.g. 0.15 = 15%) — nullable until a real guardrail
            // source is wired up in Phase 2.
            $table->decimal('price_guardrail_pct', 5, 4)->nullable();
            $table->boolean('require_shipping_template')->default(true);

            // Listing Quality Insights query toggles — Walmart includes
            // trending/WFS items by default; these flip that off when true.
            $table->boolean('exclude_trending_items')->default(false);
            $table->boolean('exclude_wfs_items')->default(false);

            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walmart_us_audit_params');
    }
};
