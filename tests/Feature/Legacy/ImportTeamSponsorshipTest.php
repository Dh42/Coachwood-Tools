<?php

namespace Tests\Feature\Legacy;

use App\Models\Order;
use App\Models\TeamSponsorshipActivity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `legacy:import-team-sponsorship` and `compare:team-sponsorship` against a
 * made-up old database (the in-memory `legacy` connection). Every value is
 * invented.
 */
class ImportTeamSponsorshipTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (['order_items', 'team_sponsorship_activity', 'orders'] as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('email');
            $t->string('team_name');
            $t->unsignedBigInteger('shopify_order_id')->unique();
            $t->dateTime('sent_at');
            $t->timestamps();
        });
        $schema->create('order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('product_label');
            $t->unsignedInteger('quantity');
            $t->timestamps();
        });
        $schema->create('team_sponsorship_activity', function (Blueprint $t) {
            $t->id();
            $t->string('email');
            $t->string('team_name');
            $t->string('team_id')->nullable();
            $t->string('user_id')->nullable();
            $t->unsignedInteger('emails_count')->nullable();
            $t->unsignedInteger('events_count')->nullable();
            $t->unsignedInteger('games_count')->nullable();
            $t->unsignedInteger('practices_count')->nullable();
            $t->dateTime('refreshed_at')->nullable();
            $t->string('last_error')->nullable();
            $t->unique(['email', 'team_name']);
        });

        $old = DB::connection('legacy');
        $old->table('orders')->insert([
            ['id' => 1, 'email' => 'coach1@example.com', 'team_name' => 'Made-up Rink', 'shopify_order_id' => 9001, 'sent_at' => '2026-09-10 14:00:00', 'created_at' => '2026-09-10 14:00:00', 'updated_at' => '2026-09-10 14:00:00'],
            ['id' => 2, 'email' => 'coach2@example.com', 'team_name' => 'Made-up Field', 'shopify_order_id' => 9002, 'sent_at' => '2026-10-02 09:30:00', 'created_at' => '2026-10-02 09:30:00', 'updated_at' => '2026-10-02 09:30:00'],
        ]);
        $old->table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'product_label' => 'Bottles', 'quantity' => 12, 'created_at' => null, 'updated_at' => null],
            ['id' => 2, 'order_id' => 1, 'product_label' => 'Hockey Pucks', 'quantity' => 12, 'created_at' => null, 'updated_at' => null],
            ['id' => 3, 'order_id' => 2, 'product_label' => 'Bottles', 'quantity' => 4, 'created_at' => null, 'updated_at' => null],
        ]);
        $old->table('team_sponsorship_activity')->insert([
            ['id' => 1, 'email' => 'coach1@example.com', 'team_name' => 'Made-up Rink', 'team_id' => 't-1', 'user_id' => 'u-1', 'emails_count' => 15, 'events_count' => 0, 'games_count' => 0, 'practices_count' => 0, 'refreshed_at' => '2026-11-20 01:00:00', 'last_error' => null],
            ['id' => 2, 'email' => 'coach2@example.com', 'team_name' => 'Made-up Field', 'team_id' => 't-2', 'user_id' => null, 'emails_count' => 9, 'events_count' => 7, 'games_count' => 3, 'practices_count' => 4, 'refreshed_at' => '2026-11-20 01:00:00', 'last_error' => 'user_id not found (no unique BioSteel user match)'],
        ]);
    }

    public function test_it_mirrors_the_three_tables_and_a_rerun_changes_nothing(): void
    {
        $this->artisan('legacy:import-team-sponsorship', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, Order::count());

        $this->artisan('legacy:import-team-sponsorship')
            ->expectsTable(self::HEADERS, [
                ['orders', 2, 2, 0, 0],
                ['order_items', 3, 3, 0, 0],
                ['team_sponsorship_activity', 2, 2, 0, 0],
            ])->assertSuccessful();

        $this->assertSame([12, 12], Order::find(1)->items()->orderBy('id')->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(7, TeamSponsorshipActivity::where('team_name', 'Made-up Field')->value('events_count'));

        $this->artisan('legacy:import-team-sponsorship')
            ->expectsTable(self::HEADERS, [
                ['orders', 2, 0, 2, 0],
                ['order_items', 3, 0, 3, 0],
                ['team_sponsorship_activity', 2, 0, 2, 0],
            ])->assertSuccessful();
    }

    public function test_a_row_here_holding_a_key_the_old_app_has_under_another_id_is_replaced(): void
    {
        // This app's own nightly refresh saw the Field team first, at ID 1.
        DB::table('team_sponsorship_activity')->insert(['id' => 1, 'email' => 'COACH2@example.com', 'team_name' => 'made-up field', 'emails_count' => 1, 'refreshed_at' => '2026-11-21 01:00:00']);

        $this->artisan('legacy:import-team-sponsorship')
            ->expectsTable(self::HEADERS, [
                ['orders', 2, 2, 0, 0],
                ['order_items', 3, 3, 0, 0],
                ['team_sponsorship_activity', 2, 2, 0, 1],
            ])->assertSuccessful();

        $this->assertSame(['Made-up Rink', 'Made-up Field'], TeamSponsorshipActivity::orderBy('id')->pluck('team_name')->all());
    }

    public function test_after_the_import_the_two_apps_match_and_a_difference_prints_no_values(): void
    {
        $this->artisan('legacy:import-team-sponsorship')->assertSuccessful();
        $this->artisan('compare:team-sponsorship')->expectsOutputToContain('Everything matches.')->assertSuccessful();

        DB::table('order_items')->where('id', 3)->update(['quantity' => 5]);
        $this->artisan('compare:team-sponsorship')
            ->expectsOutputToContain('2 section(s) differ.')
            ->doesntExpectOutputToContain('coach2@example.com')
            ->doesntExpectOutputToContain('Made-up')
            ->assertFailed();
    }
}
