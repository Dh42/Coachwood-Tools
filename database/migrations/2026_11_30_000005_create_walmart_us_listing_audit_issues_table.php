<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per individual finding per SKU per run — source distinguishes
 * Walmart's own reported issues (Phase 1) from our own rule layer's
 * findings (Phase 2, source='internal'). severity is a plain string, not an
 * enum — CDQ's own cdq_defect.severity started as an enum and needed a
 * follow-up migration to widen it once real values didn't match what was
 * assumed upfront; Walmart's real severity vocabulary is equally
 * unconfirmed right now, so this deliberately avoids repeating that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walmart_us_listing_audit_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('walmart_us_listing_audits')->cascadeOnDelete();
            $table->enum('source', ['walmart', 'internal']);
            $table->string('code', 64);
            $table->string('severity', 32)->nullable();
            $table->string('field', 255)->nullable();
            $table->text('message')->nullable();
            $table->text('recommended_action')->nullable();

            $table->index(['audit_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walmart_us_listing_audit_issues');
    }
};
