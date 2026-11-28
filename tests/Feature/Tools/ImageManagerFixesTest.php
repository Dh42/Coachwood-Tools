<?php

namespace Tests\Feature\Tools;

use App\Models\ImageAudit;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * VC Image Manager behaviour that differs from the old app on purpose: the
 * write guard (plan section 2, rule 3) and the bugs fixed in the port
 * (docs/found-issues.md 21 and 22). Every value is invented.
 */
class ImageManagerFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'user']);
        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'id',
            'services.sp_api.client_secret' => 'secret',
            'services.sp_api.vendor_refresh_token' => 'vendor-token',
            'services.sp_api.vendor_code' => 'Z0000',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
        ]);
        Storage::fake('public');
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Made-up Drink', 'type' => 'DRINK_FLAVORED', 'active' => true]);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo(Tool::where('slug', 'vc-image-manager')->firstOrFail()->permissionName());

        return $user;
    }

    /** @param  list<array<string, mixed>|\Throwable>  $patchResponses  after the listing GET */
    private function fakeListing(array ...$patchResponses): void
    {
        $sequence = Http::sequence()->push(['attributes' => ['main_product_image_locator' => [['media_location' => 'https://tools.example/live.jpg']]], 'productType' => 'DRINK_FLAVORED']);
        foreach ($patchResponses as $response) {
            $sequence->push($response);
        }
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => $sequence,
        ]);
    }

    private function upload()
    {
        return $this->actingAs($this->user())->post(route('tools.vc-image-manager.upload'), [
            'asin' => 'B1', 'variant' => 'MAIN', 'image' => UploadedFile::fake()->image('main.jpg'),
        ]);
    }

    private function storedFiles(): array
    {
        return Storage::disk('public')->allFiles('vc-image-manager');
    }

    private function realPatches(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'PATCH' && ! str_contains($request->url(), 'VALIDATION_PREVIEW'))->count();
    }

    public function test_with_writes_off_an_upload_is_validated_but_not_submitted_logged_or_kept(): void
    {
        $this->fakeListing(['status' => 'VALID']);

        $this->upload()->assertRedirect(route('tools.vc-image-manager.index', ['asin' => 'B1']));

        $this->assertStringContainsString('nothing was sent to Amazon', session('warning'));
        $this->assertStringContainsString('main_product_image_locator', session('warning'));
        $this->assertSame(0, ListingEdit::count());
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->realPatches());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'mode=VALIDATION_PREVIEW'));
    }

    public function test_with_writes_off_a_removal_is_validated_but_not_submitted(): void
    {
        $this->fakeListing(['status' => 'VALID']);

        $this->actingAs($this->user())->post(route('tools.vc-image-manager.remove'), ['asin' => 'B1', 'variant' => 'PT03'])->assertRedirect();

        $this->assertStringContainsString('nothing was sent to Amazon', session('warning'));
        $this->assertSame(0, ListingEdit::count());
        $this->assertSame(0, $this->realPatches());
    }

    public function test_the_page_says_submissions_are_off_until_cutover(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('tools.vc-image-manager.index'))
            ->assertOk()->assertSee('Submissions are off in this app until cutover.');

        config(['tools.external_writes' => true]);
        $this->actingAs($user)->get(route('tools.vc-image-manager.index'))
            ->assertOk()->assertDontSee('Submissions are off in this app until cutover.');
    }

    /** Found issue 22: a rejected upload left its file behind. */
    public function test_a_file_amazon_rejects_in_validation_is_not_kept(): void
    {
        config(['tools.external_writes' => true]);
        $this->fakeListing(['status' => 'INVALID', 'issues' => [['severity' => 'ERROR', 'message' => 'Made-up problem']]]);

        $this->upload()->assertRedirect();

        $this->assertStringContainsString('Amazon rejected this change', session('error'));
        $this->assertSame([], $this->storedFiles());
    }

    /** Found issue 22: the same when the submission itself comes back not accepted. */
    public function test_a_file_whose_submission_is_not_accepted_is_not_kept(): void
    {
        config(['tools.external_writes' => true]);
        $this->fakeListing(['status' => 'VALID'], ['status' => 'REJECTED', 'submissionId' => 'sub-9']);

        $this->upload()->assertRedirect();

        $this->assertStringContainsString('not accepted', session('error'));
        $this->assertSame('REJECTED', ListingEdit::sole()->status);
        $this->assertSame([], $this->storedFiles());
    }

    /** If the submission call itself fails, Amazon may still have taken it, so the file stays. */
    public function test_a_file_is_kept_when_the_submission_call_fails(): void
    {
        config(['tools.external_writes' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['attributes' => [], 'productType' => 'DRINK_FLAVORED']);
                }

                return str_contains($request->url(), 'VALIDATION_PREVIEW')
                    ? Http::response(['status' => 'VALID'])
                    : Http::failedConnection();
            },
        ]);

        $this->upload()->assertRedirect();

        $this->assertStringContainsString('Submission call failed', session('error'));
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_an_accepted_upload_keeps_its_file(): void
    {
        config(['tools.external_writes' => true]);
        $this->fakeListing(['status' => 'VALID'], ['status' => 'ACCEPTED', 'submissionId' => 'sub-1']);

        $this->upload()->assertRedirect();

        $this->assertCount(1, $this->storedFiles());
        $this->assertStringEndsWith($this->storedFiles()[0], json_decode(ListingEdit::sole()->new_value, true)[0]['media_location']);
    }

    /** Found issue 21: the no-JavaScript audit looped through every product with no pause. */
    public function test_the_no_javascript_audit_pauses_between_products(): void
    {
        Sleep::fake();
        Product::create(['asin' => 'B2', 'sku' => 'SKU-2', 'title' => 'Made-up Two', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Product::create(['asin' => 'B3', 'sku' => 'SKU-3', 'title' => 'Made-up Three', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
        ]);

        $this->actingAs($this->user())->post(route('tools.vc-image-manager.audit.run'))->assertRedirect();

        $this->assertSame(3, ImageAudit::count());
        Sleep::assertSleptTimes(2);
    }

    public function test_the_tool_is_registered_once(): void
    {
        $tool = Tool::where('url', '/tools/vc-image-manager')->sole();
        $this->assertSame('vc-image-manager', $tool->slug);
        $this->assertSame('Amazon · Listings', $tool->group);
        $this->assertTrue(Permission::where('name', 'tool.vc-image-manager')->exists());

        (require database_path('migrations/2026_12_07_000002_register_vc_image_manager.php'))->up();
        $this->assertSame(1, Tool::where('slug', 'vc-image-manager')->count());
    }
}
