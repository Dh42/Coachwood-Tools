<?php

namespace Tests\Feature\Tools;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqDefect;
use App\Models\CdqListingSnapshot;
use App\Models\CdqScore;
use App\Models\Product;
use App\Models\Tool;
use App\Models\User;
use Database\Seeders\CdqCheckConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CdqReportTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $this->tool = Tool::where('slug', 'cdq-report')->firstOrFail();
        $this->seed(CdqCheckConfigSeeder::class);
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    private function scoredSnapshot(array $overrides = [], array $scoreOverrides = []): CdqListingSnapshot
    {
        $version = CdqCheckConfigVersion::orderByDesc('effective_from')->first();
        $snapshot = CdqListingSnapshot::create(array_merge([
            'asin' => 'B0REPORT1',
            'sku' => 'SKU-REPORT',
            'marketplace_id' => 'A2EUQ1WTGCTBG2',
            'content_hash' => hash('sha256', uniqid('', true)),
            'catalog_json' => ['images' => [['images' => []]]],
            'attributes_json' => [],
            'issues_checked_at' => now(),
            'captured_at' => now(),
        ], $overrides));

        CdqScore::create(array_merge([
            'snapshot_id' => $snapshot->id,
            'check_config_version_id' => $version->id,
            'attr_score' => 25, 'text_score' => 15, 'image_score' => 18, 'aplus_score' => 16, 'consist_score' => 10,
            'total_score' => 84, 'grade' => 'B', 'scored_at' => now(),
        ], $scoreOverrides));

        return $snapshot;
    }

    public function test_users_without_the_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.cdq-report.index'))->assertForbidden();
    }

    public function test_index_shows_grade_counts_and_the_asin_list(): void
    {
        $snapshot = $this->scoredSnapshot();
        Product::create(['asin' => 'B0REPORT1', 'sku' => 'SKU-REPORT', 'title' => 'Report Test Product', 'type' => 't', 'active' => true]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.cdq-report.index'));

        $response->assertOk();
        $response->assertSee('B0REPORT1');
        $response->assertSee('Report Test Product');
    }

    public function test_show_displays_defects_for_the_asin(): void
    {
        $snapshot = $this->scoredSnapshot();
        CdqDefect::create([
            'snapshot_id' => $snapshot->id, 'dimension' => 'TEXT', 'check_code' => 'DESCRIPTION_MISSING',
            'severity' => 'ERROR', 'points' => 5, 'attribute_path' => 'rtip_product_description',
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.cdq-report.show', 'B0REPORT1'));

        $response->assertOk();
        $response->assertSee('DESCRIPTION_MISSING');
    }

    public function test_show_404s_for_an_asin_with_no_snapshot(): void
    {
        $this->actingAs($this->userWithAccess())->get(route('tools.cdq-report.show', 'B0NOTFOUND'))->assertNotFound();
    }

    public function test_export_csv_returns_a_csv_download(): void
    {
        $this->scoredSnapshot();

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.cdq-report.export-csv'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertSee('B0REPORT1');
    }
}
