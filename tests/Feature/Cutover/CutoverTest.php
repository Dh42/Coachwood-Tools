<?php

namespace Tests\Feature\Cutover;

use App\Cutover\Cutover;
use App\Models\ScProduct;
use App\Services\Amazon\SpApiClient;
use App\Services\AmazonListings\ListingChannel;
use App\Services\Klaviyo\KlaviyoClient;
use App\Support\ExternalWrites;
use App\Support\ExternalWritesDisabled;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Moving tools from the old app one group at a time (decision 2026-12-07,
 * build spec section 14.3): per-group writes, imports that stop once their
 * group has moved, and the cutover:* and legacy:sync commands. Every value
 * is invented.
 */
class CutoverTest extends TestCase
{
    use RefreshDatabase;

    /** A made-up group whose import and comparison are test commands. */
    private function fakeGroup(bool $compareMatches = true): void
    {
        $GLOBALS['cutover_test_calls'] = [];
        Artisan::command('legacy:import-made-up {--dry-run}', function () {
            $GLOBALS['cutover_test_calls'][] = 'import'.($this->option('dry-run') ? ' --dry-run' : '');

            return 0;
        });
        Artisan::command('compare:made-up', function () use ($compareMatches) {
            $GLOBALS['cutover_test_calls'][] = 'compare';

            return $compareMatches ? 0 : 1;
        });
        config([
            'cutover.shared_imports' => [],
            'cutover.groups' => ['made-up' => [
                'label' => 'Made-up Tool', 'tools' => ['made-up-tool'], 'imports' => ['legacy:import-made-up'],
                'compares' => ['compare:made-up'], 'task_prefixes' => ['made-up:'], 'after_move' => ['Made-up next step.'],
            ]],
        ]);
    }

    public function test_writes_are_allowed_per_group_once_it_has_moved(): void
    {
        $this->assertFalse(ExternalWrites::allowed('listings'));
        $this->assertFalse(ExternalWrites::allowed());

        Cutover::markMoved('listings');

        $this->assertTrue(ExternalWrites::allowed('listings'));
        $this->assertFalse(ExternalWrites::allowed('team-sponsorship'));
        $this->assertFalse(ExternalWrites::allowed(), 'a write that names no group still needs TOOLS_EXTERNAL_WRITES');

        config(['tools.external_writes' => true]);
        $this->assertTrue(ExternalWrites::allowed('team-sponsorship'));
    }

    public function test_a_moved_group_writes_through_its_own_clients_and_no_other(): void
    {
        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.vendor_refresh_token' => 'vendor-token',
            'services.sp_api.vendor_code' => 'Z0000',
            'klaviyo.accounts.BS' => ['name' => 'BioSteel', 'private_api_key' => 'made-up-key'],
        ]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/*' => Http::response(['status' => 'ACCEPTED']),
            'a.klaviyo.com/*' => Http::response('', 202),
        ]);
        Cutover::markMoved('listings');

        $this->assertSame('ACCEPTED', app(SpApiClient::class)->patchListing('SKU-1', 'DRINK_FLAVORED', [], false, ListingChannel::Vendor)['body']['status']);

        // Team Sponsorship hasn't moved: its Klaviyo event is still refused.
        $this->assertFalse((new KlaviyoClient('BS', 'team-sponsorship'))->sendEvent('Made-up Event', 'coach@example.com')['success']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'klaviyo'));
        Cutover::markMoved('team-sponsorship');
        $this->assertTrue((new KlaviyoClient('BS', 'team-sponsorship'))->sendEvent('Made-up Event', 'coach@example.com')['success']);
    }

    public function test_the_error_says_the_tool_has_not_moved(): void
    {
        $this->expectException(ExternalWritesDisabled::class);
        $this->expectExceptionMessage("hasn't moved from the old app yet");

        ExternalWrites::ensureAllowed('Made-up write', 'listings');
    }

    public function test_an_import_refuses_once_its_group_has_moved(): void
    {
        Cutover::markMoved('team-sponsorship');

        $this->artisan('legacy:import-team-sponsorship')
            ->expectsOutputToContain('This app owns its data now')
            ->assertFailed();
    }

    public function test_the_products_import_stops_copying_sc_products_once_the_listings_tools_move(): void
    {
        $legacy = Schema::connection('legacy');
        foreach (['products', 'sc_products'] as $table) {
            $legacy->dropIfExists($table);
        }
        $legacy->create('products', function (Blueprint $t) {
            $t->id();
            $t->string('asin');
            $t->string('sku')->nullable();
            $t->string('title');
            $t->string('type');
            $t->decimal('list_price', 10, 2)->nullable();
            $t->decimal('cost', 10, 2)->nullable();
            $t->decimal('cogs', 10, 2)->nullable();
            $t->boolean('active');
            $t->timestamps();
        });
        $legacy->create('sc_products', function (Blueprint $t) {
            $t->id();
            $t->string('asin');
            $t->string('sku');
            $t->string('title');
            $t->string('status');
            $t->dateTime('synced_at');
            $t->timestamp('created_at')->nullable();
        });
        ScProduct::create(['asin' => 'B9', 'sku' => 'SKU-SYNCED-HERE', 'title' => 'Made-up Listing', 'status' => 'Active', 'synced_at' => now()]);
        Cutover::markMoved('listings');

        $this->artisan('legacy:import-products')->expectsOutputToContain('sc_products is skipped')->assertSuccessful();

        $this->assertSame(['SKU-SYNCED-HERE'], ScProduct::pluck('sku')->all());
    }

    public function test_move_runs_the_final_import_and_comparison_then_switches_the_group_over(): void
    {
        $this->fakeGroup();

        $this->artisan('cutover:move', ['group' => 'made-up'])
            ->expectsConfirmation('Are its tools and tasks switched off in the old app?', 'yes')
            ->expectsOutputToContain('Made-up Tool now runs in this app')
            ->expectsOutputToContain('Made-up next step.')
            ->assertSuccessful();

        $this->assertSame(['import', 'compare'], $GLOBALS['cutover_test_calls']);
        $this->assertTrue(Cutover::hasMoved('made-up'));
    }

    public function test_move_changes_nothing_when_the_comparison_differs_or_it_is_not_confirmed(): void
    {
        $this->fakeGroup(compareMatches: false);

        $this->artisan('cutover:move', ['group' => 'made-up'])
            ->expectsConfirmation('Are its tools and tasks switched off in the old app?', 'no')
            ->assertFailed();
        $this->assertSame([], $GLOBALS['cutover_test_calls']);

        $this->artisan('cutover:move', ['group' => 'made-up', '--force' => true])
            ->expectsOutputToContain('found differences. Nothing was moved')
            ->assertFailed();
        $this->assertFalse(Cutover::hasMoved('made-up'));
    }

    public function test_undo_moves_a_group_back(): void
    {
        $this->fakeGroup();
        Cutover::markMoved('made-up');

        $this->artisan('cutover:status')->expectsOutputToContain('this app')->assertSuccessful();
        $this->artisan('cutover:undo', ['group' => 'made-up', '--force' => true])->assertSuccessful();

        $this->assertFalse(Cutover::hasMoved('made-up'));
        $this->artisan('cutover:move', ['group' => 'no-such-group'])->assertFailed();
    }

    public function test_sync_runs_each_group_still_in_the_old_app_and_skips_moved_ones(): void
    {
        $this->fakeGroup();

        $this->artisan('legacy:sync')->expectsOutputToContain('Sync complete.')->assertSuccessful();
        $this->assertSame(['import', 'compare'], $GLOBALS['cutover_test_calls']);

        $GLOBALS['cutover_test_calls'] = [];
        $this->artisan('legacy:sync', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(['import --dry-run'], $GLOBALS['cutover_test_calls']);

        $GLOBALS['cutover_test_calls'] = [];
        Cutover::markMoved('made-up');
        $this->artisan('legacy:sync')->expectsOutputToContain('skipped')->assertSuccessful();
        $this->assertSame([], $GLOBALS['cutover_test_calls']);
    }

    public function test_sync_fails_when_a_comparison_differs(): void
    {
        $this->fakeGroup(compareMatches: false);

        $this->artisan('legacy:sync')->expectsOutputToContain('DIFFERS')->assertFailed();
    }
}
