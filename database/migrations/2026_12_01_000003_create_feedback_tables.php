<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * US Product Reviews — Amazon's own review-topic classification
     * (Customer Feedback API, NOT Data Kiosk — that path is CA-blocked at
     * the marketplace level for this feature, confirmed live in the
     * legacy build: A2EUQ1WTGCTBG2 isn't in this API's own marketplaceId
     * enum). US marketplace only, but covers every ASIN across both our
     * catalogs — confirmed live the data is identical regardless of which
     * of our two SP-API accounts asks, since it's brand-level Amazon data.
     * Column shapes ported verbatim from the legacy feedback_schema.sql.
     */
    public function up(): void
    {
        // One row per pull attempt per ASIN, always appended, never
        // overwritten. Records the HTTP outcome itself (200/204/403/404/5xx)
        // since "no content" (204) is a meaningfully different, legitimate
        // outcome from an error, and both differ from "never checked."
        // source_catalog captures which of our own catalogs the ASIN was
        // found in AT THIS SNAPSHOT TIME — a property of our business, not
        // of Amazon's data (which is identical either way, confirmed live).
        Schema::create('feedback_raw_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('asin', 20);
            $table->string('marketplace_id', 20)->default('ATVPDKIKX0DER');
            $table->string('source_catalog', 10); // 'vc' | 'sc' | 'both'
            $table->unsignedSmallInteger('http_status');
            $table->string('country_code', 5)->nullable();
            $table->date('data_start_date')->nullable(); // Amazon's own trailing-window bounds for this pull
            $table->date('data_end_date')->nullable();
            $table->dateTime('pulled_at');

            $table->index(['asin', 'snapshot_date'], 'feedback_raw_asin_date');
        });
        // Blueprint has no longBlob() helper — gzcompress()'d raw JSON,
        // indefinitely, per the topic field being schema-drift-prone
        // (confirmed useful in legacy: parent/child ASIN fields were added
        // to the parser later and backfilled by re-parsing stored raw
        // responses, no new API call needed).
        DB::statement('ALTER TABLE feedback_raw_snapshots ADD raw_response_gz LONGBLOB NULL');

        // One row per (snapshot_date, asin, polarity, topic) — the parsed
        // core. Never overwritten — a new snapshot is always new rows, so
        // change detection reads this as a real time series. Parent/child-
        // ASIN (variation family) columns: null for standalone ASINs with
        // no variation family (most of the catalog) is Amazon's own "not
        // enough family data" signal, not a parsing gap.
        Schema::create('feedback_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_snapshot_id')->constrained('feedback_raw_snapshots')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('asin', 20);
            $table->string('polarity', 10); // 'positive' | 'negative'
            $table->string('topic', 120);
            $table->unsignedInteger('number_of_mentions')->nullable();
            $table->decimal('occurrence_percentage', 6, 2)->nullable();
            $table->decimal('star_rating_impact', 6, 3)->nullable();
            $table->decimal('browse_node_occurrence_pct', 6, 2)->nullable(); // category benchmark, same call
            $table->decimal('browse_node_star_impact', 6, 3)->nullable();
            $table->unsignedInteger('parent_asin_mentions')->nullable();
            $table->decimal('parent_asin_occurrence_pct', 6, 2)->nullable();
            $table->decimal('parent_asin_star_impact', 6, 3)->nullable();
            $table->string('child_most_mentions_asin', 20)->nullable();
            $table->unsignedInteger('child_most_mentions_count')->nullable();
            $table->string('child_least_mentions_asin', 20)->nullable();
            $table->unsignedInteger('child_least_mentions_count')->nullable();

            $table->index(['asin', 'snapshot_date'], 'feedback_topics_asin_date');
        });

        // Child rows: up to 5 subtopics per topic, each with its own mention count.
        Schema::create('feedback_subtopics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('feedback_topics')->cascadeOnDelete();
            $table->string('subtopic', 160);
            $table->unsignedInteger('number_of_mentions')->nullable();
            $table->decimal('occurrence_percentage', 6, 2)->nullable();

            $table->index('topic_id', 'feedback_subtopics_topic');
        });

        // Verbatim review snippets, tagged to the topic or subtopic they came
        // from and the snapshot they appeared in — rotation itself is a
        // signal per the legacy brief, so snippets are never deduped across
        // snapshots.
        Schema::create('feedback_snippets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('feedback_topics')->cascadeOnDelete();
            $table->foreignId('subtopic_id')->nullable()->constrained('feedback_subtopics')->cascadeOnDelete(); // null = snippet attached at topic level, not a specific subtopic
            $table->string('snippet', 500);

            $table->index('topic_id', 'feedback_snippets_topic');
        });

        // 6-month monthly trend per topic, from the separate
        // getItemReviewTrends operation — a SECOND API call per ASIN,
        // deliberately not pulled alongside the main topics call (doubles
        // collector volume), run via its own scheduled task instead. Own
        // raw/parsed table pair, same append-only discipline as above.
        Schema::create('feedback_trend_raw_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('asin', 20);
            $table->string('marketplace_id', 20)->default('ATVPDKIKX0DER');
            $table->string('source_catalog', 10);
            $table->unsignedSmallInteger('http_status');
            $table->dateTime('pulled_at');

            $table->index(['asin', 'snapshot_date'], 'feedback_trend_raw_asin_date');
        });
        DB::statement('ALTER TABLE feedback_trend_raw_snapshots ADD raw_response_gz LONGBLOB NULL');

        // Only occurrencePercentage is available per month (no
        // numberOfMentions at this grain — confirmed from Amazon's own
        // schema, ReviewTrendMetrics has exactly one field).
        Schema::create('feedback_topic_trends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_trend_snapshot_id')->constrained('feedback_trend_raw_snapshots')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('asin', 20);
            $table->string('polarity', 10);
            $table->string('topic', 120);
            $table->date('month_start');
            $table->date('month_end');
            $table->decimal('asin_occurrence_pct', 6, 2)->nullable();
            $table->decimal('parent_asin_occurrence_pct', 6, 2)->nullable();
            $table->decimal('browse_node_occurrence_pct', 6, 2)->nullable();

            $table->index(['asin', 'topic', 'month_start'], 'feedback_topic_trends_asin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_topic_trends');
        Schema::dropIfExists('feedback_trend_raw_snapshots');
        Schema::dropIfExists('feedback_snippets');
        Schema::dropIfExists('feedback_subtopics');
        Schema::dropIfExists('feedback_topics');
        Schema::dropIfExists('feedback_raw_snapshots');
    }
};
