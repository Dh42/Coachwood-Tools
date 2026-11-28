<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The VC and SC Listings Editors' eight tables, in the old app's final shape
 * (user_id on the edit tables, alias slots on the configs, listing issues
 * and restrictions on the audits). The two channels keep separate tables, so
 * a VC/SC ASIN collision can never mix the two accounts' histories.
 *
 * - `*listing_edits`: every submission sent to Amazon, with its result.
 *   `listing_edits` is shared with the VC Image Manager (step 12).
 * - `*listing_audit`: append-only, one row per product per audit run; the
 *   page reads the latest per product.
 * - `*listing_audit_config`: the configurable audit columns, append-only;
 *   the latest `effective_from` wins.
 * - `*listing_audit_attribute_catalog`: every attribute key an audit has
 *   seen, for the column picker.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['' => 'products', 'sc_' => 'sc_products'] as $prefix => $products) {
            Schema::create($prefix.'listing_edits', function (Blueprint $table) use ($prefix, $products) {
                $table->id();
                $table->foreignId('product_id')->nullable()->constrained($products)->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('asin', 20);
                $table->string('sku', 40);
                $table->string('attribute', 60);
                $table->text('old_value')->nullable();
                $table->text('new_value');
                $table->string('submission_id', 64)->nullable();
                $table->string('status', 20);
                $table->text('issues_json')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('asin');
                if ($prefix === 'sc_') {
                    $table->index('sku');
                }
            });

            Schema::create($prefix.'listing_audit', function (Blueprint $table) use ($products) {
                $table->id();
                $table->foreignId('product_id')->constrained($products)->cascadeOnDelete();
                $table->boolean('has_serving_quantity')->default(false);
                $table->boolean('has_energy_content')->default(false);
                $table->boolean('has_total_fat')->default(false);
                $table->boolean('has_total_carbohydrate')->default(false);
                $table->boolean('has_protein')->default(false);
                $table->unsignedTinyInteger('bullet_point_count')->default(0);
                $table->boolean('has_regulatory_compliance')->default(false);
                $table->string('brand_code', 20)->nullable();
                $table->string('manufacturer_code', 20)->nullable();
                $table->string('replenishment_category', 40)->nullable();
                $table->string('aplus_status', 20)->nullable(); // full, brand_only or none
                $table->unsignedTinyInteger('image_count')->nullable();
                $table->unsignedTinyInteger('contribution_mismatch_count')->nullable();
                $table->string('contribution_mismatch_attrs')->nullable();
                $table->text('audited_attributes')->nullable(); // JSON {attribute_key: {kind, value}}
                $table->text('listing_issues')->nullable();
                $table->text('listing_restrictions')->nullable();
                $table->string('fetch_error')->nullable();
                $table->dateTime('checked_at');

                $table->index(['product_id', 'checked_at']);
            });

            Schema::create($prefix.'listing_audit_config', function (Blueprint $table) {
                $table->id();
                $table->dateTime('effective_from');
                for ($i = 1; $i <= 7; $i++) {
                    $table->string("attr_slot_{$i}", 80)->nullable();
                    $table->string("alias_slot_{$i}", 40)->nullable();
                }
                $table->string('reason')->nullable();
                $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            });

            Schema::create($prefix.'listing_audit_attribute_catalog', function (Blueprint $table) {
                $table->string('attribute_key', 80)->primary();
                $table->dateTime('first_seen_at');
                $table->dateTime('last_seen_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['sc_', ''] as $prefix) {
            foreach (['listing_audit_attribute_catalog', 'listing_audit_config', 'listing_audit', 'listing_edits'] as $table) {
                Schema::dropIfExists($prefix.$table);
            }
        }
    }
};
