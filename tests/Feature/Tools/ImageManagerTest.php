<?php

namespace Tests\Feature\Tools;

use App\Models\ImageAudit;
use App\Models\ListingEdit;
use App\Models\Product;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImageManagerTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $this->tool = Tool::where('slug', 'vc-image-manager')->firstOrFail();

        config([
            'services.sp_api.base_url' => 'https://sp-api.test',
            'services.sp_api.client_id' => 'id',
            'services.sp_api.client_secret' => 'secret',
            'services.sp_api.vendor_refresh_token' => 'vendor-token',
            'services.sp_api.vendor_code' => 'Z0000',
            'services.sp_api.vendor_marketplace_id' => 'A2EUQ1WTGCTBG2',
        ]);

        // These tests are the old app's, where submissions always went to
        // Amazon. Writes are off by default here (plan section 2, rule 3);
        // ImageManagerFixesTest covers that path.
        config(['tools.external_writes' => true]);

        Http::fake(['api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake'])]);
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    public function test_users_without_the_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.vc-image-manager.index'))->assertForbidden();
    }

    public function test_admins_can_access_without_the_permission_checkbox(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('tools.vc-image-manager.index'))->assertOk();
    }

    public function test_picker_shows_active_products(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Visible Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-image-manager.index'));

        $response->assertOk()->assertSee('Visible Product');
    }

    public function test_selecting_a_product_loads_the_grid_with_live_thumbnails(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['images' => [['images' => [
                ['variant' => 'MAIN', 'link' => 'https://amazon.example/main.jpg'],
            ]]]]),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-image-manager.index', ['asin' => 'B1']));

        $response->assertOk()->assertSee('https://amazon.example/main.jpg', false);
    }

    public function test_grid_shows_our_submission_alongside_live_with_a_status_badge(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['images' => [['images' => [
                ['variant' => 'MAIN', 'link' => 'https://amazon.example/main.jpg'],
            ]]]]),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => [
                'main_product_image_locator' => [['media_location' => 'https://tools.example/ours.jpg']],
            ]]),
        ]);
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'main_product_image_locator',
            'old_value' => '[]', 'new_value' => json_encode([['media_location' => 'https://tools.example/ours.jpg']]), 'status' => 'ACCEPTED',
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-image-manager.index', ['asin' => 'B1', 'ajax' => 1]));

        $response->assertOk();
        $response->assertSee('https://amazon.example/main.jpg', false);
        $response->assertSee('https://tools.example/ours.jpg', false);
        $response->assertSee('Live');
    }

    public function test_grid_shows_not_live_when_our_submission_does_not_match_live(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['images' => [['images' => []]]]),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
        ]);
        $product = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ListingEdit::create([
            'product_id' => $product->id, 'asin' => 'B1', 'sku' => 'SKU-1', 'attribute' => 'main_product_image_locator',
            'old_value' => '[]', 'new_value' => json_encode([['media_location' => 'https://tools.example/ours.jpg']]), 'status' => 'ACCEPTED',
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-image-manager.index', ['asin' => 'B1', 'ajax' => 1]));

        $response->assertOk();
        $response->assertSee('Not live');
    }

    public function test_ajax_request_returns_only_the_grid_fragment(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/catalog/2022-04-01/items/*' => Http::response(['images' => [['images' => []]]]),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-image-manager.index', ['asin' => 'B1', 'ajax' => 1]));

        $response->assertOk();
        $response->assertDontSee('Coachwood Group Tools');
        $response->assertSee('Main');
    }

    public function test_upload_stores_the_file_previews_then_submits_and_logs(): void
    {
        Storage::fake('public');
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::sequence()
                ->push(['attributes' => [], 'productType' => 'DRINK_FLAVORED'])
                ->push(['status' => 'VALID'])
                ->push(['status' => 'ACCEPTED', 'submissionId' => 'sub-1']),
        ]);

        $user = $this->userWithAccess();
        $response = $this->actingAs($user)->post(route('tools.vc-image-manager.upload'), [
            'asin' => 'B1',
            'variant' => 'MAIN',
            'image' => UploadedFile::fake()->image('main.jpg'),
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('accepted by Amazon', session('status'));

        $edit = ListingEdit::firstOrFail();
        $this->assertSame('main_product_image_locator', $edit->attribute);
        $this->assertSame('ACCEPTED', $edit->status);
        $this->assertSame($user->id, $edit->user_id);

        Storage::disk('public')->assertExists(str_replace(Storage::disk('public')->url(''), '', json_decode($edit->new_value, true)[0]['media_location']));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'mode=VALIDATION_PREVIEW'));
    }

    public function test_upload_rejects_a_non_image_file(): void
    {
        Storage::fake('public');
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->post(route('tools.vc-image-manager.upload'), [
            'asin' => 'B1',
            'variant' => 'MAIN',
            'image' => UploadedFile::fake()->create('not-an-image.pdf', 100),
        ]);

        $response->assertSessionHasErrors('image');
        $this->assertSame(0, ListingEdit::count());
    }

    public function test_a_preview_rejection_stops_before_the_real_submission_and_logs_nothing(): void
    {
        Storage::fake('public');
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::sequence()
                ->push(['attributes' => [], 'productType' => 'DRINK_FLAVORED'])
                ->push(['status' => 'INVALID', 'issues' => [['severity' => 'ERROR', 'message' => 'Bad image']]]),
        ]);

        $response = $this->actingAs($this->userWithAccess())->post(route('tools.vc-image-manager.upload'), [
            'asin' => 'B1',
            'variant' => 'MAIN',
            'image' => UploadedFile::fake()->image('main.jpg'),
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('Amazon rejected this change', session('error'));
        $this->assertSame(0, ListingEdit::count());
    }

    public function test_remove_submits_an_empty_value_and_logs_it(): void
    {
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::sequence()
                ->push(['attributes' => ['main_product_image_locator' => [['media_location' => 'https://tools.example/main.jpg']]], 'productType' => 'DRINK_FLAVORED'])
                ->push(['status' => 'VALID'])
                ->push(['status' => 'ACCEPTED', 'submissionId' => 'sub-2']),
        ]);

        $response = $this->actingAs($this->userWithAccess())->post(route('tools.vc-image-manager.remove'), [
            'asin' => 'B1',
            'variant' => 'MAIN',
        ]);

        $response->assertRedirect();
        $edit = ListingEdit::firstOrFail();
        $this->assertSame('main_product_image_locator', $edit->attribute);
        $this->assertSame('[]', $edit->new_value);
    }

    public function test_unattributed_summary_lists_flagged_products_only(): void
    {
        $flagged = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Flagged Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        $clean = Product::create(['asin' => 'B2', 'sku' => 'SKU-2', 'title' => 'Clean Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ImageAudit::create(['product_id' => $flagged->id, 'unattributed_variants' => 'MAIN', 'unattributed_count' => 1, 'checked_at' => now()]);
        ImageAudit::create(['product_id' => $clean->id, 'unattributed_variants' => null, 'unattributed_count' => 0, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-image-manager.index'));

        $response->assertOk();
        $response->assertSee('Not showing our contribution — 1 product(s)');
    }

    public function test_not_live_summary_lists_flagged_products_only(): void
    {
        $flagged = Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'Flagged Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        $clean = Product::create(['asin' => 'B2', 'sku' => 'SKU-2', 'title' => 'Clean Product', 'type' => 'DRINK_FLAVORED', 'active' => true]);
        ImageAudit::create(['product_id' => $flagged->id, 'not_live_variants' => 'MAIN', 'not_live_count' => 1, 'checked_at' => now()]);
        ImageAudit::create(['product_id' => $clean->id, 'not_live_variants' => null, 'not_live_count' => 0, 'checked_at' => now()]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.vc-image-manager.index'));

        $response->assertOk();
        $response->assertSee('Submitted but not live — 1 product(s)');
    }

    public function test_audit_batch_returns_json_progress(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'fake']),
            'sp-api.test/listings/2021-08-01/items/*' => Http::response(['attributes' => []]),
        ]);
        Product::create(['asin' => 'B1', 'sku' => 'SKU-1', 'title' => 'T', 'type' => 'DRINK_FLAVORED', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->postJson(route('tools.vc-image-manager.audit.batch'), ['offset' => 0, 'batch' => 5]);

        $response->assertOk()->assertJson(['processed' => 1, 'done' => true]);
    }
}
