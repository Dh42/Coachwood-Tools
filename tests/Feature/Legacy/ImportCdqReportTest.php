<?php

namespace Tests\Feature\Legacy;

use App\Models\ScheduledTask;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `legacy:import-cdq-report`, `compare:cdq-report` and
 * `legacy:import-vc-traffic` against a made-up old database (the in-memory
 * `legacy` connection). Every value is invented.
 */
class ImportCdqReportTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['cdq_llm_cache', 'cdq_title_length_band', 'cdq_search_critical_attribute', 'cdq_score', 'cdq_defect', 'cdq_check_config', 'cdq_check_config_version', 'cdq_listing_snapshot', 'cdq_product_type_schema', 'vc_traffic_current'];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('cdq_product_type_schema', function (Blueprint $t) {
            $t->id();
            $t->string('product_type');
            $t->string('marketplace_id');
            $t->string('schema_version')->nullable();
            $t->text('required_json');
            $t->text('recommended_json');
            $t->longText('raw_schema');
            $t->dateTime('fetched_at');
            $t->timestamps();
        });
        $schema->create('cdq_listing_snapshot', function (Blueprint $t) {
            $t->id();
            foreach (['asin', 'marketplace_id', 'content_hash', 'catalog_json', 'captured_at'] as $c) {
                $t->string($c);
            }
            foreach (['sku', 'vendor_code', 'product_type', 'parent_asin', 'attributes_json', 'main_image_hash', 'aplus_json', 'issues_json', 'issues_checked_at'] as $c) {
                $t->string($c)->nullable();
            }
        });
        $schema->create('cdq_check_config_version', function (Blueprint $t) {
            $t->id();
            $t->dateTime('effective_from');
            $t->string('reason')->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
        });
        $schema->create('cdq_check_config', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('version_id');
            $t->string('check_code');
            $t->string('dimension');
            $t->decimal('points', 6, 2);
            $t->boolean('per_instance')->default(false);
            $t->decimal('instance_cap', 6, 2)->nullable();
            $t->boolean('enabled')->default(true);
            $t->string('notes')->nullable();
        });
        $schema->create('cdq_defect', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('snapshot_id');
            $t->string('dimension');
            $t->string('check_code');
            $t->string('severity');
            $t->decimal('points', 6, 2);
            $t->string('attribute_path')->nullable();
            $t->text('detail_json')->nullable();
        });
        $schema->create('cdq_score', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('snapshot_id');
            $t->unsignedBigInteger('check_config_version_id');
            foreach (['attr_score', 'text_score', 'image_score', 'aplus_score', 'consist_score', 'total_score'] as $c) {
                $t->decimal($c, 5, 2)->nullable();
            }
            $t->string('grade')->nullable();
            $t->dateTime('scored_at');
        });
        $schema->create('cdq_search_critical_attribute', function (Blueprint $t) {
            $t->id();
            $t->string('marketplace_id');
            $t->string('product_type')->nullable();
            $t->string('attribute_name');
            $t->string('notes')->nullable();
        });
        $schema->create('cdq_title_length_band', function (Blueprint $t) {
            $t->id();
            $t->string('marketplace_id');
            $t->string('product_type');
            $t->unsignedSmallInteger('min_length');
            $t->unsignedSmallInteger('max_length');
            $t->string('notes')->nullable();
        });
        $schema->create('cdq_llm_cache', function (Blueprint $t) {
            $t->string('content_hash');
            $t->string('model');
            $t->string('prompt_version');
            $t->longText('response_json');
            $t->dateTime('created_at');
        });
        $schema->create('vc_traffic_current', function (Blueprint $t) {
            $t->id();
            $t->string('dedup_key');
            $t->string('asin');
            $t->date('period_start');
            $t->date('period_end');
            $t->unsignedInteger('glance_views')->nullable();
            foreach (['lost_featured_offer_pct', 'sourceable_roos_pct', 'sell_through_rate_pct'] as $c) {
                $t->decimal($c, 7, 4)->nullable();
            }
            $t->dateTime('pulled_at');
        });

        $old = DB::connection('legacy');
        $old->table('cdq_product_type_schema')->insert(['id' => 3, 'product_type' => 'MADE_UP_TYPE', 'marketplace_id' => 'A2EUQ1WTGCTBG2', 'required_json' => '["item_name"]', 'recommended_json' => '[]', 'raw_schema' => '{"made":"up"}', 'fetched_at' => '2026-11-22 04:00:00']);
        $old->table('cdq_listing_snapshot')->insert(['id' => 50, 'asin' => 'B0MADEUP1', 'marketplace_id' => 'A2EUQ1WTGCTBG2', 'content_hash' => str_repeat('a', 64), 'catalog_json' => '{"images":[]}', 'attributes_json' => '{"item_name":[{"value":"Made-up"}]}', 'captured_at' => '2026-11-22 04:15:00']);
        $old->table('cdq_check_config_version')->insert(['id' => 1, 'effective_from' => '2026-09-01 00:00:00', 'reason' => 'Made-up revision']);
        $old->table('cdq_check_config')->insert(['id' => 7, 'version_id' => 1, 'check_code' => 'DESCRIPTION_MISSING', 'dimension' => 'TEXT', 'points' => 5]);
        $old->table('cdq_defect')->insert(['id' => 60, 'snapshot_id' => 50, 'dimension' => 'TEXT', 'check_code' => 'DESCRIPTION_MISSING', 'severity' => 'ERROR', 'points' => 5]);
        $old->table('cdq_score')->insert(['id' => 70, 'snapshot_id' => 50, 'check_config_version_id' => 1, 'attr_score' => 30, 'text_score' => 15, 'image_score' => 20, 'aplus_score' => 20, 'consist_score' => 10, 'total_score' => 95, 'grade' => 'A', 'scored_at' => '2026-11-22 04:45:00']);
        $old->table('cdq_search_critical_attribute')->insert(['id' => 2, 'marketplace_id' => 'A2EUQ1WTGCTBG2', 'attribute_name' => 'flavor']);
        $old->table('cdq_title_length_band')->insert(['id' => 4, 'marketplace_id' => 'A2EUQ1WTGCTBG2', 'product_type' => 'MADE_UP_TYPE', 'min_length' => 80, 'max_length' => 200]);
        $old->table('vc_traffic_current')->insert(['id' => 9, 'dedup_key' => str_repeat('b', 64), 'asin' => 'B0MADEUP1', 'period_start' => '2026-11-16', 'period_end' => '2026-11-22', 'glance_views' => 321, 'pulled_at' => '2026-11-23 02:00:00']);
    }

    public function test_it_copies_every_table_with_its_ids(): void
    {
        $this->artisan('legacy:import-cdq-report')->assertSuccessful();

        $this->assertSame('B0MADEUP1', DB::table('cdq_listing_snapshot')->where('id', 50)->value('asin'));
        $this->assertSame(50, (int) DB::table('cdq_score')->where('id', 70)->value('snapshot_id'));
        $this->assertSame(1, DB::table('cdq_check_config')->where('version_id', 1)->count());
        $this->assertSame(1, DB::table('cdq_defect')->where('snapshot_id', 50)->count());
        $this->assertSame('{"made":"up"}', DB::table('cdq_product_type_schema')->where('id', 3)->value('raw_schema'));
    }

    public function test_this_apps_own_runs_are_replaced_and_its_scores_are_unchecked_again(): void
    {
        // This app's Sunday run took the same score ID and checked it.
        DB::table('cdq_listing_snapshot')->insert(['id' => 51, 'asin' => 'B0SHADOW', 'marketplace_id' => 'A2EUQ1WTGCTBG2', 'content_hash' => str_repeat('c', 64), 'catalog_json' => '{}', 'captured_at' => '2026-11-29 04:15:00']);
        DB::table('cdq_check_config_version')->insert(['id' => 1, 'effective_from' => '2026-09-01 00:00:00']);
        DB::table('cdq_score')->insert(['id' => 70, 'snapshot_id' => 51, 'check_config_version_id' => 1, 'consist_score' => 4, 'total_score' => 70, 'grade' => 'C', 'scored_at' => '2026-11-29 04:45:00', 'consist_checked_at' => '2026-11-29 05:15:00']);

        $this->artisan('legacy:import-cdq-report')->assertSuccessful();

        $this->assertSame([50], DB::table('cdq_listing_snapshot')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $score = DB::table('cdq_score')->where('id', 70)->first();
        $this->assertSame(50, (int) $score->snapshot_id);
        $this->assertNull($score->consist_checked_at);
    }

    public function test_this_apps_anthropic_answers_are_kept(): void
    {
        DB::table('cdq_llm_cache')->insert(['content_hash' => str_repeat('d', 64), 'model' => 'made-up-model', 'prompt_version' => 'v1', 'response_json' => '{"conflicts":[]}', 'created_at' => '2026-11-29 05:15:00']);
        DB::connection('legacy')->table('cdq_llm_cache')->insert(['content_hash' => str_repeat('e', 64), 'model' => 'made-up-model', 'prompt_version' => 'v1', 'response_json' => '{"conflicts":[]}', 'created_at' => '2026-11-22 05:15:00']);

        $this->artisan('legacy:import-cdq-report')->assertSuccessful();

        $this->assertSame(2, DB::table('cdq_llm_cache')->count());
    }

    public function test_running_it_again_changes_nothing_and_a_dry_run_saves_nothing(): void
    {
        $this->artisan('legacy:import-cdq-report', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('cdq_listing_snapshot')->count());

        $this->artisan('legacy:import-cdq-report')->assertSuccessful();
        $this->artisan('legacy:import-cdq-report')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['cdq_product_type_schema', 1, 0, 1, 0],
                ['cdq_listing_snapshot', 1, 0, 1, 0],
                ['cdq_check_config_version', 1, 0, 1, 0],
                ['cdq_check_config', 1, 0, 1, 0],
                ['cdq_defect', 1, 0, 1, 0],
                ['cdq_score', 1, 0, 1, 0],
                ['cdq_search_critical_attribute', 1, 0, 1, 0],
                ['cdq_title_length_band', 1, 0, 1, 0],
                ['cdq_llm_cache', 0, 0, 0, 0],
            ])
            ->assertSuccessful();
    }

    public function test_after_the_import_the_two_apps_match_and_a_difference_prints_no_values(): void
    {
        $this->artisan('legacy:import-cdq-report')->assertSuccessful();
        $this->artisan('compare:cdq-report')->expectsOutputToContain('Everything matches.')->assertSuccessful();

        DB::table('cdq_listing_snapshot')->where('id', 50)->update(['catalog_json' => '{"edited":"here"}']);
        $this->artisan('compare:cdq-report')
            ->expectsOutputToContain('1 section(s) differ.')
            ->doesntExpectOutputToContain('edited')
            ->doesntExpectOutputToContain('B0MADEUP1')
            ->assertFailed();
    }

    public function test_vc_traffic_is_copied_and_refreshed_daily(): void
    {
        $this->artisan('legacy:import-vc-traffic')->assertSuccessful();

        $this->assertSame(321, (int) DB::table('vc_traffic_current')->where('id', 9)->value('glance_views'));
        $this->assertSame('40 0 * * *', ScheduledTask::where('command', 'legacy:import-vc-traffic')->sole()->cron_expression);
    }
}
