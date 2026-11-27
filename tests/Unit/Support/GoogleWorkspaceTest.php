<?php

namespace Tests\Unit\Support;

use App\Models\GoogleWorkspaceDomain;
use App\Support\GoogleWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_a_configured_domain_case_insensitively(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $this->assertTrue(GoogleWorkspace::isCompanyDomain('person@coachwoodgroup.com'));
        $this->assertTrue(GoogleWorkspace::isCompanyDomain('person@CoachwoodGroup.COM'));
    }

    public function test_rejects_any_other_domain(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $this->assertFalse(GoogleWorkspace::isCompanyDomain('person@example.com'));
        $this->assertFalse(GoogleWorkspace::isCompanyDomain('person@notcoachwoodgroup.com'));
    }

    public function test_nothing_matches_when_no_domain_is_configured(): void
    {
        $this->assertFalse(GoogleWorkspace::isCompanyDomain('person@coachwoodgroup.com'));
        $this->assertSame([], GoogleWorkspace::allowedDomains());
    }

    public function test_a_workspace_org_spanning_several_domains_matches_any_of_them(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
        GoogleWorkspaceDomain::create(['domain' => 'biosteel.com']);
        GoogleWorkspaceDomain::create(['domain' => 'bornrun.ca']);

        $this->assertTrue(GoogleWorkspace::isCompanyDomain('person@coachwoodgroup.com'));
        $this->assertTrue(GoogleWorkspace::isCompanyDomain('person@biosteel.com'));
        $this->assertTrue(GoogleWorkspace::isCompanyDomain('person@BornRun.ca'));
        $this->assertFalse(GoogleWorkspace::isCompanyDomain('person@example.com'));
    }

    public function test_allowed_domains_returns_every_configured_domain(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'biosteel.com']);
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $this->assertEqualsCanonicalizing(['biosteel.com', 'coachwoodgroup.com'], GoogleWorkspace::allowedDomains());
    }
}
