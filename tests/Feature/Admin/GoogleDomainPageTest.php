<?php

namespace Tests\Feature\Admin;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Admin > Google Sign-In beyond the ported GoogleDomainTest. */
class GoogleDomainPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_the_sidebar_links_the_page_for_admins(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')
            ->assertSee('href="'.route('admin.google-domains.index').'"', false);
    }

    public function test_removing_the_last_domain_warns_that_it_locks_everyone_out(): void
    {
        $domain = GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);

        $this->actingAs($this->admin)->get(route('admin.google-domains.index'))
            ->assertSee('id="remove-domain-'.$domain->id.'"', false)
            ->assertSee('This is the last domain');
    }

    public function test_removing_one_of_several_domains_names_who_is_affected(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
        GoogleWorkspaceDomain::create(['domain' => 'biosteel.com']);

        $this->actingAs($this->admin)->get(route('admin.google-domains.index'))
            ->assertSee('People with a biosteel.com account will no longer be able to sign in.')
            ->assertDontSee('This is the last domain');
    }

    public function test_adding_and_removing_a_domain_is_logged(): void
    {
        $this->actingAs($this->admin)->post(route('admin.google-domains.store'), ['domain' => ' Example.COM ']);
        $domain = GoogleWorkspaceDomain::where('domain', 'example.com')->sole();
        $this->actingAs($this->admin)->delete(route('admin.google-domains.destroy', $domain));

        $this->assertSame(['Google sign-in domain added', 'Google sign-in domain removed'], Activity::query()->inLog('admin')->orderBy('id')->pluck('description')->all());
        $this->assertSame('example.com', Activity::query()->latest('id')->first()->getExtraProperty('domain'));
    }

    public function test_an_invalid_domain_shows_its_error_next_to_the_field(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.google-domains.index'))
            ->followingRedirects()
            ->post(route('admin.google-domains.store'), ['domain' => 'not a domain'])
            ->assertSee('Enter a valid domain, e.g. coachwoodgroup.com.')
            ->assertSee('aria-invalid="true"', false);
    }
}
