<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASP Tracker (Canadian Protein and BioSteel): the old app's six tables in
 * their final shape, after its later migrations added the BioSteel store,
 * kept_order_discount, is_draft renamed to is_excluded, and the
 * asp_ingest_log (kind, started_at) index from the 2026-11-16 incident.
 * `asp_sales_lines` is keyed on Shopify's line item ID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asp_sales_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('line_item_id')->primary();
            $table->string('store', 10);
            $table->unsignedBigInteger('order_id')->index();
            $table->string('order_name', 32);
            $table->dateTime('order_created_at');
            $table->date('order_date');
            $table->date('week_start');
            $table->string('source_name', 64)->nullable();
            $table->string('app_name', 120)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('sku', 120)->nullable()->index();
            $table->string('product_title')->nullable();
            $table->string('variant_title')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('original_unit_price', 12, 2);
            $table->decimal('list_unit_price', 12, 2)->nullable();
            $table->string('list_price_source', 20)->nullable();
            $table->decimal('variant_price', 12, 2)->nullable();
            $table->decimal('line_level_discount', 12, 2)->default(0);
            $table->decimal('order_level_discount', 12, 2)->default(0);
            $table->decimal('selling_plan_discount', 12, 2)->default(0);
            $table->decimal('net_line_total', 12, 2);
            $table->string('discount_labels', 500)->nullable();
            $table->string('selling_plan_name', 120)->nullable();
            $table->boolean('is_subscription')->default(false);
            $table->boolean('is_free_item')->default(false);
            $table->unsignedInteger('refunded_qty')->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('financial_status', 32)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->char('content_hash', 40);
            $table->timestamps();

            $table->index(['store', 'order_date'], 'asp_lines_store_date');
            $table->index(['store', 'product_id', 'order_date'], 'asp_lines_store_product_date');
            $table->index(['store', 'variant_id', 'is_subscription', 'order_date'], 'asp_lines_store_variant_sub_date');
            $table->index(['store', 'is_subscription', 'list_price_source'], 'asp_lines_store_sub_listsrc');
        });

        Schema::create('asp_line_discounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('line_item_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('label');
            $table->string('label_group');
            $table->string('discount_type', 20);
            $table->string('level', 10);
            $table->decimal('amount', 12, 2);
        });

        Schema::create('asp_pending_orders', function (Blueprint $table) {
            $table->id();
            $table->string('store', 10);
            $table->unsignedBigInteger('order_id');
            $table->string('reason', 40);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['store', 'order_id']);
        });

        Schema::create('asp_ingest_log', function (Blueprint $table) {
            $table->id();
            $table->string('store', 10);
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('bulk_operation_id', 120)->nullable();
            $table->date('since')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('orders_touched')->default(0);
            $table->unsignedInteger('lines_changed')->default(0);
            $table->unsignedInteger('unattributed_refunds')->default(0);
            $table->text('errors')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store', 'kind', 'started_at']);
            $table->index(['kind', 'started_at'], 'asp_ingest_log_kind_started_at');
        });

        Schema::create('asp_daily_sales', function (Blueprint $table) {
            $table->id();
            $table->string('store', 10);
            $table->date('order_date');
            $table->date('week_start');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->boolean('is_subscription');
            $table->boolean('is_free_item');
            $table->boolean('is_excluded');
            $table->string('app_name', 120)->nullable();
            $table->unsignedInteger('line_count');
            $table->integer('gross_qty');
            $table->integer('net_qty');
            $table->decimal('net_revenue', 14, 2);
            $table->decimal('list_total', 14, 2);
            $table->decimal('line_discount', 14, 2);
            $table->decimal('order_discount', 14, 2);
            $table->decimal('kept_order_discount', 14, 2)->default(0);
            $table->decimal('selling_plan_discount', 14, 2);
            $table->integer('full_price_qty');
            $table->unsignedBigInteger('latest_line_item_id');

            $table->index(['store', 'order_date'], 'asp_daily_sales_store_date');
            $table->index(['store', 'variant_id', 'order_date'], 'asp_daily_sales_store_variant_date');
            $table->index(['store', 'product_id', 'order_date'], 'asp_daily_sales_store_product_date');
        });

        Schema::create('asp_daily_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('store', 10);
            $table->date('order_date');
            $table->date('week_start');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->boolean('is_subscription');
            $table->boolean('is_free_item');
            $table->boolean('is_excluded');
            $table->string('app_name', 120)->nullable();
            $table->string('label_group');
            $table->decimal('amount', 14, 2);

            $table->index(['store', 'order_date'], 'asp_daily_disc_store_date');
        });
    }

    public function down(): void
    {
        foreach (['asp_daily_discounts', 'asp_daily_sales', 'asp_ingest_log', 'asp_pending_orders', 'asp_line_discounts', 'asp_sales_lines'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
