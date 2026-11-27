<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BioSteel Shopify Lost Sales (renamed from Stockout): the old app's six
 * tables in their final shape, after its later migrations added `sku`,
 * `substitution_note`, the `inv_snap_item_time_variant` index and
 * `inventory_item_variants`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw readings from the 15-minute sweep (and, after cutover, the
        // webhook). About 333,000 rows a day; stockout:prune-snapshots keeps 90 days.
        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_item_id');
            $table->string('variant_id')->nullable();
            $table->string('location_id');
            $table->string('state', 32);
            $table->integer('quantity');
            $table->timestamp('captured_at');
            $table->string('source', 16);
            $table->timestamps();

            $table->index(['inventory_item_id', 'location_id', 'state', 'captured_at'], 'inv_snap_item_loc_state_time');
            $table->index(['variant_id', 'captured_at'], 'inv_snap_variant_time');
            $table->index(['inventory_item_id', 'captured_at', 'variant_id'], 'inv_snap_item_time_variant');
        });

        // Lets the webhook find a variant with a primary-key read (2026-11-16 incident).
        Schema::create('inventory_item_variants', function (Blueprint $table) {
            $table->string('inventory_item_id')->primary();
            $table->string('variant_id');
            $table->timestamps();
        });

        Schema::create('inventory_history_daily', function (Blueprint $table) {
            $table->id();
            $table->string('product_title');
            $table->date('day');
            $table->integer('ending_inventory_units');
            $table->timestamp('ingested_at');
            $table->timestamps();

            $table->unique(['product_title', 'day']);
        });

        Schema::create('sales_history_daily', function (Blueprint $table) {
            $table->id();
            $table->string('product_title');
            $table->date('day');
            $table->integer('net_items_sold');
            $table->decimal('net_sales', 12, 2);
            $table->timestamp('ingested_at');
            $table->timestamps();

            $table->unique(['product_title', 'day']);
        });

        Schema::create('variant_availability_intervals', function (Blueprint $table) {
            $table->id();
            $table->string('variant_id');
            $table->string('sku')->nullable();
            $table->string('product_title')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('source', 32);
            $table->string('confidence', 32);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['variant_id', 'started_at']);
            $table->index(['source', 'started_at']);
        });

        Schema::create('lost_sales_estimates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('variant_availability_interval_id');
            $table->string('variant_id');
            $table->string('sku')->nullable();
            $table->string('product_title')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->decimal('days_out_of_stock', 8, 2);
            $table->decimal('baseline_units_per_day', 10, 2);
            $table->decimal('baseline_revenue_per_day', 12, 2);
            $table->unsignedInteger('baseline_window_days_used');
            $table->string('baseline_confidence', 32);
            $table->text('substitution_note')->nullable();
            $table->decimal('gross_units_lost', 10, 2);
            $table->decimal('gross_revenue_lost', 12, 2);
            $table->string('model_version', 32);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index('variant_availability_interval_id');
            $table->index(['variant_id', 'started_at']);
            $table->index('gross_revenue_lost');
        });
    }

    public function down(): void
    {
        foreach (['lost_sales_estimates', 'variant_availability_intervals', 'sales_history_daily', 'inventory_history_daily', 'inventory_item_variants', 'inventory_snapshots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
