<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Seller Central (US) product list, in the old app's final shape.
 * Read-only here in Phase 0, like `products`: `legacy:import-products`
 * copies it from the old app with its IDs, daily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sc_products', function (Blueprint $table) {
            $table->id();
            $table->string('asin', 20);
            $table->string('sku', 40)->unique();
            $table->string('title');
            $table->string('status', 20)->default('Active');
            $table->dateTime('synced_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index('asin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sc_products');
    }
};
