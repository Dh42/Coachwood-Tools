<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Vendor Central (.ca) product list, in the old app's final shape.
 * Read-only here in Phase 0 (plan section 5, "Shared tables the old app
 * keeps owning"): `legacy:import-products` copies it from the old app with
 * its IDs, daily, until Phase 1 rebuilds the catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('asin', 20)->unique();
            $table->string('sku', 40)->nullable();
            $table->string('title');
            $table->string('type', 60);
            $table->decimal('list_price', 10, 2)->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->decimal('cogs', 10, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
