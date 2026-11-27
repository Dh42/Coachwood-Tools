<?php

namespace Tests\Feature\Tools;

use App\Models\Tool;
use App\Models\User;
use App\Models\WalmartUsListingAudit;
use App\Models\WalmartUsListingAuditIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WalmartUsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $this->tool = Tool::where('slug', 'walmart-us-listing-auditor')->firstOrFail();
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

    protected function auditedSku(string $sku = 'SKU-REPORT'): WalmartUsListingAudit
    {
        return WalmartUsListingAudit::create([
            'sku' => $sku,
            'as_of_date' => '2026-09-01',
            'run_at' => now(),
            'listing_quality' => 82.5,
            'content_score' => 90,
        ]);
    }

    public function test_users_without_the_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.walmart-us-listing-auditor.index'))->assertForbidden();
    }

    public function test_index_shows_audited_skus(): void
    {
        $this->auditedSku();

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.walmart-us-listing-auditor.index'));

        $response->assertOk();
        $response->assertSee('SKU-REPORT');
    }

    public function test_index_renders_an_empty_state_with_no_data(): void
    {
        $response = $this->actingAs($this->userWithAccess())->get(route('tools.walmart-us-listing-auditor.index'));

        $response->assertOk();
        $response->assertSee('nightly ingest');
    }

    public function test_show_displays_issues_for_the_sku(): void
    {
        $audit = $this->auditedSku();
        WalmartUsListingAuditIssue::create([
            'audit_id' => $audit->id,
            'source' => 'walmart',
            'code' => 'MAIN_IMAGE_MISSING',
            'severity' => 'critical',
        ]);

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.walmart-us-listing-auditor.show', 'SKU-REPORT'));

        $response->assertOk();
        $response->assertSee('MAIN_IMAGE_MISSING');
    }

    public function test_show_404s_for_a_sku_with_no_audit(): void
    {
        $this->actingAs($this->userWithAccess())->get(route('tools.walmart-us-listing-auditor.show', 'SKU-NOT-FOUND'))->assertNotFound();
    }

    public function test_the_dashboard_surfaces_the_tool_once_the_permission_is_granted(): void
    {
        $response = $this->actingAs($this->userWithAccess())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Walmart US Listing Auditor');
    }
}
