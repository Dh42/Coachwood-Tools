<?php

namespace Tests\Feature\Legacy;

use App\Models\FeedbackRawSnapshot;
use App\Models\FeedbackTopic;
use App\Models\Product;
use App\Models\ScheduledTask;
use App\Models\ScProduct;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `legacy:import-products`, `legacy:import-us-product-reviews` and
 * `compare:us-product-reviews` against a made-up old database (the
 * in-memory `legacy` connection). Every value is invented.
 */
class ImportProductsAndReviewsTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_TABLES = ['feedback_topic_trends', 'feedback_trend_raw_snapshots', 'feedback_snippets', 'feedback_subtopics', 'feedback_topics', 'feedback_raw_snapshots', 'products', 'sc_products'];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (self::OLD_TABLES as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('products', function (Blueprint $t) {
            $t->id();
            $t->string('asin')->unique();
            $t->string('sku')->nullable();
            $t->string('title');
            $t->string('type');
            $t->decimal('list_price', 10, 2)->nullable();
            $t->decimal('cost', 10, 2)->nullable();
            $t->decimal('cogs', 10, 2)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        $schema->create('sc_products', function (Blueprint $t) {
            $t->id();
            $t->string('asin');
            $t->string('sku')->unique();
            $t->string('title');
            $t->string('status')->default('Active');
            $t->dateTime('synced_at');
            $t->timestamp('created_at')->nullable();
        });
        $schema->create('feedback_raw_snapshots', function (Blueprint $t) {
            $t->id();
            $t->date('snapshot_date');
            $t->string('asin');
            $t->string('marketplace_id')->default('ATVPDKIKX0DER');
            $t->string('source_catalog');
            $t->unsignedSmallInteger('http_status');
            $t->string('country_code')->nullable();
            $t->date('data_start_date')->nullable();
            $t->date('data_end_date')->nullable();
            $t->dateTime('pulled_at');
            $t->binary('raw_response_gz')->nullable();
        });
        $schema->create('feedback_topics', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('raw_snapshot_id');
            $t->date('snapshot_date');
            $t->string('asin');
            $t->string('polarity');
            $t->string('topic');
            foreach (['number_of_mentions', 'parent_asin_mentions', 'child_most_mentions_count', 'child_least_mentions_count'] as $c) {
                $t->unsignedInteger($c)->nullable();
            }
            foreach (['occurrence_percentage', 'star_rating_impact', 'browse_node_occurrence_pct', 'browse_node_star_impact', 'parent_asin_occurrence_pct', 'parent_asin_star_impact'] as $c) {
                $t->decimal($c, 6, 3)->nullable();
            }
            $t->string('child_most_mentions_asin')->nullable();
            $t->string('child_least_mentions_asin')->nullable();
        });
        $schema->create('feedback_subtopics', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('topic_id');
            $t->string('subtopic');
            $t->unsignedInteger('number_of_mentions')->nullable();
            $t->decimal('occurrence_percentage', 6, 2)->nullable();
        });
        $schema->create('feedback_snippets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('topic_id');
            $t->unsignedBigInteger('subtopic_id')->nullable();
            $t->string('snippet', 500);
        });
        $schema->create('feedback_trend_raw_snapshots', function (Blueprint $t) {
            $t->id();
            $t->date('snapshot_date');
            $t->string('asin');
            $t->string('marketplace_id')->default('ATVPDKIKX0DER');
            $t->string('source_catalog');
            $t->unsignedSmallInteger('http_status');
            $t->dateTime('pulled_at');
            $t->binary('raw_response_gz')->nullable();
        });
        $schema->create('feedback_topic_trends', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('raw_trend_snapshot_id');
            $t->date('snapshot_date');
            $t->string('asin');
            $t->string('polarity');
            $t->string('topic');
            $t->date('month_start');
            $t->date('month_end');
            foreach (['asin_occurrence_pct', 'parent_asin_occurrence_pct', 'browse_node_occurrence_pct'] as $c) {
                $t->decimal($c, 6, 2)->nullable();
            }
        });

        $old = DB::connection('legacy');
        $old->table('products')->insert([['id' => 40, 'asin' => 'B0MADEUP1', 'sku' => 'SKU-1', 'title' => 'Made-up Bar', 'type' => 'FOOD', 'active' => true]]);
        $old->table('sc_products')->insert([['id' => 41, 'asin' => 'B0MADEUP2', 'sku' => 'SC-2', 'title' => 'Made-up Mix', 'synced_at' => '2026-11-01 00:00:00', 'created_at' => '2026-10-01 00:00:00']]);
        $old->table('feedback_raw_snapshots')->insert([['id' => 300, 'snapshot_date' => '2026-11-22', 'asin' => 'B0MADEUP1', 'source_catalog' => 'vc', 'http_status' => 200, 'pulled_at' => '2026-11-22 01:00:05', 'raw_response_gz' => gzcompress('{"topics":{}}', 9)]]);
        $old->table('feedback_topics')->insert([['id' => 310, 'raw_snapshot_id' => 300, 'snapshot_date' => '2026-11-22', 'asin' => 'B0MADEUP1', 'polarity' => 'negative', 'topic' => 'Made-up texture', 'number_of_mentions' => 5, 'occurrence_percentage' => 12.5]]);
        $old->table('feedback_subtopics')->insert([['id' => 320, 'topic_id' => 310, 'subtopic' => 'Made-up chalky', 'number_of_mentions' => 3]]);
        $old->table('feedback_snippets')->insert([['id' => 330, 'topic_id' => 310, 'subtopic_id' => 320, 'snippet' => 'Made-up snippet text']]);
        $old->table('feedback_trend_raw_snapshots')->insert([['id' => 340, 'snapshot_date' => '2026-11-22', 'asin' => 'B0MADEUP1', 'source_catalog' => 'vc', 'http_status' => 200, 'pulled_at' => '2026-11-22 02:00:05']]);
        $old->table('feedback_topic_trends')->insert([['id' => 350, 'raw_trend_snapshot_id' => 340, 'snapshot_date' => '2026-11-22', 'asin' => 'B0MADEUP1', 'polarity' => 'negative', 'topic' => 'Made-up texture', 'month_start' => '2026-10-01', 'month_end' => '2026-10-31', 'asin_occurrence_pct' => 11.0]]);
    }

    public function test_products_are_copied_with_their_ids_and_rows_gone_from_the_old_app_are_removed(): void
    {
        Product::factory()->create(['id' => 99, 'asin' => 'B0STALE']);

        $this->artisan('legacy:import-products')->assertSuccessful();

        $this->assertSame([40], Product::query()->pluck('id')->all());
        $this->assertSame(41, ScProduct::where('sku', 'SC-2')->value('id'));
    }

    public function test_products_are_refreshed_daily_by_a_scheduled_task(): void
    {
        $this->assertSame('30 0 * * *', ScheduledTask::where('command', 'legacy:import-products')->sole()->cron_expression);
    }

    public function test_the_review_tables_become_an_exact_copy_with_ids_and_raw_responses(): void
    {
        $this->artisan('legacy:import-us-product-reviews')->assertSuccessful();

        $raw = FeedbackRawSnapshot::findOrFail(300);
        $this->assertSame('{"topics":{}}', gzuncompress($raw->raw_response_gz));
        $topic = FeedbackTopic::findOrFail(310);
        $this->assertSame(['Made-up chalky'], $topic->subtopics->pluck('subtopic')->all());
        $this->assertSame('Made-up snippet text', DB::table('feedback_snippets')->where('id', 330)->value('snippet'));
        $this->assertSame(1, DB::table('feedback_topic_trends')->where('raw_trend_snapshot_id', 340)->count());
    }

    public function test_rows_this_app_collected_itself_are_replaced_so_ids_never_collide(): void
    {
        // This app's shadow collector ran first and took IDs the old app also uses.
        DB::table('feedback_raw_snapshots')->insert(['id' => 300, 'snapshot_date' => '2026-11-29', 'asin' => 'B0SHADOW', 'source_catalog' => 'sc', 'http_status' => 204, 'pulled_at' => '2026-11-29 01:00:00']);
        DB::table('feedback_raw_snapshots')->insert(['id' => 301, 'snapshot_date' => '2026-11-29', 'asin' => 'B0SHADOW2', 'source_catalog' => 'sc', 'http_status' => 204, 'pulled_at' => '2026-11-29 01:00:01']);

        $this->artisan('legacy:import-us-product-reviews')->assertSuccessful();

        $this->assertSame([300], FeedbackRawSnapshot::query()->pluck('id')->all());
        $this->assertSame('B0MADEUP1', FeedbackRawSnapshot::findOrFail(300)->asin);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('legacy:import-us-product-reviews')->assertSuccessful();

        $this->artisan('legacy:import-us-product-reviews')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['feedback_raw_snapshots', 1, 0, 1, 0],
                ['feedback_topics', 1, 0, 1, 0],
                ['feedback_subtopics', 1, 0, 1, 0],
                ['feedback_snippets', 1, 0, 1, 0],
                ['feedback_trend_raw_snapshots', 1, 0, 1, 0],
                ['feedback_topic_trends', 1, 0, 1, 0],
            ])
            ->assertSuccessful();
    }

    public function test_after_both_imports_the_two_apps_match(): void
    {
        $this->artisan('legacy:import-products')->assertSuccessful();
        $this->artisan('legacy:import-us-product-reviews')->assertSuccessful();

        $this->artisan('compare:us-product-reviews')
            ->expectsOutputToContain('Everything matches.')
            ->assertSuccessful();
    }

    public function test_a_difference_is_reported_without_printing_values(): void
    {
        $this->artisan('legacy:import-products')->assertSuccessful();
        $this->artisan('legacy:import-us-product-reviews')->assertSuccessful();
        DB::table('feedback_topics')->where('id', 310)->update(['topic' => 'Edited here']);

        $this->artisan('compare:us-product-reviews')
            ->expectsOutputToContain('2 section(s) differ.')
            ->doesntExpectOutputToContain('Edited here')
            ->doesntExpectOutputToContain('Made-up')
            ->assertFailed();
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('legacy:import-us-product-reviews', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, FeedbackRawSnapshot::count());
    }
}
