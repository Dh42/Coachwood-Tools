<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CDQ Listing Quality Report: the old app's nine `cdq_*` tables in their
 * final shape (after its later migrations added attributes_json and
 * main_image_hash, and fixed the severity enum), plus `vc_traffic_current`,
 * and one new column, `cdq_score.consist_checked_at` (found issue 13),
 * which CDQ reads for glance-view weights and which is copied from the old
 * app until Phase 1 replaces it (plan section 5, "Shared tables").
 *
 * The check config, search-critical attributes and title bands are data,
 * not seeds: they come from the old app through `legacy:import-cdq-report`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cdq_product_type_schema', function (Blueprint $table) {
            $table->id();
            $table->string('product_type', 64);
            $table->string('marketplace_id', 20);
            $table->string('schema_version', 32)->nullable();
            $table->text('required_json');
            $table->text('recommended_json');
            $table->longText('raw_schema');
            $table->dateTime('fetched_at');
            $table->timestamps();

            $table->unique(['product_type', 'marketplace_id']);
        });

        Schema::create('cdq_listing_snapshot', function (Blueprint $table) {
            $table->id();
            $table->string('asin', 10);
            $table->string('sku', 64)->nullable();
            $table->string('vendor_code', 16)->nullable();
            $table->string('marketplace_id', 20);
            $table->string('product_type', 64)->nullable();
            $table->string('parent_asin', 10)->nullable();
            $table->char('content_hash', 64);
            $table->longText('catalog_json');
            $table->longText('attributes_json')->nullable();
            $table->char('main_image_hash', 64)->nullable();
            $table->longText('aplus_json')->nullable();
            $table->longText('issues_json')->nullable();
            $table->dateTime('issues_checked_at')->nullable();
            $table->dateTime('captured_at');

            $table->unique(['asin', 'marketplace_id', 'content_hash']);
            $table->index(['asin', 'marketplace_id', 'captured_at']);
        });

        Schema::create('cdq_check_config_version', function (Blueprint $table) {
            $table->id();
            $table->dateTime('effective_from');
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('cdq_check_config', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('cdq_check_config_version')->cascadeOnDelete();
            $table->string('check_code', 64);
            $table->string('dimension', 16);
            $table->decimal('points', 6, 2);
            $table->boolean('per_instance')->default(false);
            $table->decimal('instance_cap', 6, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('notes')->nullable();

            $table->unique(['version_id', 'check_code']);
        });

        Schema::create('cdq_defect', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('cdq_listing_snapshot')->cascadeOnDelete();
            $table->enum('dimension', ['ATTR', 'TEXT', 'IMAGE', 'APLUS', 'CONSIST']);
            $table->string('check_code', 64);
            $table->enum('severity', ['INFO', 'WARN', 'ERROR', 'CRITICAL']);
            $table->decimal('points', 6, 2);
            $table->string('attribute_path')->nullable();
            $table->text('detail_json')->nullable();

            $table->index(['snapshot_id', 'dimension']);
        });

        Schema::create('cdq_score', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('cdq_listing_snapshot')->cascadeOnDelete();
            $table->foreignId('check_config_version_id')->constrained('cdq_check_config_version');
            foreach (['attr_score', 'text_score', 'image_score', 'aplus_score', 'consist_score', 'total_score'] as $column) {
                $table->decimal($column, 5, 2)->nullable();
            }
            $table->enum('grade', ['A', 'B', 'C', 'D', 'U'])->nullable();
            $table->dateTime('scored_at');
            // New here (found issue 13): when cdq:consistency really checked
            // this score. Null means consist_score is cdq:score's provisional
            // full credit. The old app has no such column.
            $table->dateTime('consist_checked_at')->nullable();

            $table->unique(['snapshot_id', 'check_config_version_id']);
            $table->index(['snapshot_id', 'scored_at']);
        });

        Schema::create('cdq_search_critical_attribute', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace_id', 20);
            $table->string('product_type', 64)->nullable();
            $table->string('attribute_name', 128);
            $table->string('notes')->nullable();

            $table->unique(['marketplace_id', 'product_type', 'attribute_name'], 'cdq_search_critical_attr_unique');
        });

        Schema::create('cdq_title_length_band', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace_id', 20);
            $table->string('product_type', 64);
            $table->unsignedSmallInteger('min_length');
            $table->unsignedSmallInteger('max_length');
            $table->string('notes')->nullable();

            $table->unique(['marketplace_id', 'product_type'], 'cdq_title_length_band_unique');
        });

        Schema::create('cdq_llm_cache', function (Blueprint $table) {
            $table->char('content_hash', 64);
            $table->string('model', 64);
            $table->string('prompt_version', 16);
            $table->longText('response_json');
            $table->dateTime('created_at');

            $table->primary(['content_hash', 'model', 'prompt_version']);
        });

        Schema::create('vc_traffic_current', function (Blueprint $table) {
            $table->id();
            $table->char('dedup_key', 64)->unique('vc_traffic_current_dedup_key');
            $table->string('asin', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('glance_views')->nullable();
            $table->decimal('lost_featured_offer_pct', 7, 4)->nullable();
            $table->decimal('sourceable_roos_pct', 7, 4)->nullable();
            $table->decimal('sell_through_rate_pct', 7, 4)->nullable();
            $table->dateTime('pulled_at');

            $table->index(['asin', 'period_start'], 'vc_traffic_current_asin_period');
        });
    }

    public function down(): void
    {
        foreach (['vc_traffic_current', 'cdq_llm_cache', 'cdq_title_length_band', 'cdq_search_critical_attribute', 'cdq_score', 'cdq_defect', 'cdq_check_config', 'cdq_check_config_version', 'cdq_listing_snapshot', 'cdq_product_type_schema'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
