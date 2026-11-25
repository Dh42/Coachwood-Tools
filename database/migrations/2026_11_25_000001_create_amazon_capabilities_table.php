<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The capability registry (spec section 6, layer 2): what this account can
 * call, as last measured by `amazon:probe`. One row per capability key,
 * replaced on each probe run. Collectors check it before building on an
 * endpoint or field (working rule 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('label');
            $table->string('family', 20);          // report, data_kiosk, api
            $table->string('account', 10);         // vendor, seller
            $table->string('marketplace_id', 20);
            $table->string('status', 20);          // pass, partial, empty, role_missing, fail, timeout, skipped
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->json('fields_requested')->nullable();
            $table->json('fields_rejected')->nullable();
            $table->json('field_stats')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->json('notes')->nullable();
            $table->timestamp('probed_at');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_capabilities');
    }
};
