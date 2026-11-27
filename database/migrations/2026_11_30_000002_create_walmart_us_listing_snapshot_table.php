<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only raw-payload history, deduped by content_hash — same shape and
 * reasoning as cdq_listing_snapshot: a re-run with unchanged content is a
 * no-op (the unique index below already exists), a real content change
 * inserts a new row, so a future score-movement diff can always find what
 * actually changed between two points in time. Not in the brief's own
 * suggested table list — added for structural parity with CDQ, the closest
 * existing "listing auditor" this tool is modelled on, whose whole design
 * depends on scoring off a stored raw snapshot rather than the live fetch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walmart_us_listing_snapshot', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64);
            $table->string('wpid', 32)->nullable();
            $table->char('content_hash', 64);
            $table->longText('item_json'); // raw catalog/item payload
            $table->longText('listing_quality_json')->nullable(); // raw per-item Listing Quality payload, when available
            $table->dateTime('captured_at');

            $table->index(['sku', 'captured_at']);
            $table->unique(['sku', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walmart_us_listing_snapshot');
    }
};
