<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per product line on a sponsorship order. Populated two ways: at
 * creation time going forward (TeamSponsorshipController::storeOrder()) and
 * via a one-time backfill command for the ~593 historical orders that
 * predate this table (team-sponsorship:backfill-order-items).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('product_label'); // e.g. "Bottles" — matches config('team_sponsorship.*.label') when known, else Shopify's own raw line-item title
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
