<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The VC Image Manager's audit, in the old app's final shape (not-live
 * columns included): append-only, one row per product per audit run. The
 * page reads the latest per product. Image submissions themselves are
 * logged in `listing_edits`, shared with the VC Listings Editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('unattributed_variants')->nullable();
            $table->unsignedTinyInteger('unattributed_count')->nullable();
            $table->string('not_live_variants')->nullable();
            $table->unsignedTinyInteger('not_live_count')->nullable();
            $table->string('fetch_error')->nullable();
            $table->dateTime('checked_at');

            $table->index(['product_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_audits');
    }
};
