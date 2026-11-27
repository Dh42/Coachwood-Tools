<?php

namespace Tests\Feature\Platform;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** The `auth` log lines beyond the ported AccountDeactivationTest. */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function googleReturns(string $id, string $email, ?string $hostedDomain): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake(['id' => $id, 'name' => 'Someone', 'email' => $email, 'hd' => $hostedDomain]));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function lastAuthEntry(): Activity
    {
        return Activity::query()->inLog('auth')->latest('id')->firstOrFail();
    }

    public function test_records_are_kept_for_365_days_as_in_the_old_app(): void
    {
        $this->assertSame(365, config('activitylog.delete_records_older_than_days'));
    }

    public function test_a_first_google_sign_in_logs_the_new_account_then_the_sign_in(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
        $this->googleReturns('google-1', 'new@coachwoodgroup.com', 'coachwoodgroup.com');

        $this->get('/auth/google/callback');

        $user = User::where('email', 'new@coachwoodgroup.com')->firstOrFail();
        $this->assertSame(['Google account created', 'Login succeeded'], Activity::query()->inLog('auth')->orderBy('id')->pluck('description')->all());
        $this->assertSame('google', $this->lastAuthEntry()->getExtraProperty('method'));
        $this->assertTrue($this->lastAuthEntry()->causer->is($user));
    }

    public function test_a_refusal_records_the_address_that_was_tried(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
        $this->googleReturns('google-2', 'someone@gmail.com', null);

        $this->get('/auth/google/callback');

        $this->assertSame('someone@gmail.com', $this->lastAuthEntry()->getExtraProperty('email'));
    }

    public function test_sign_in_with_no_domain_configured_is_logged(): void
    {
        $this->googleReturns('google-3', 'someone@coachwoodgroup.com', 'coachwoodgroup.com');

        $this->get('/auth/google/callback');

        $this->assertSame('Login blocked - no domain configured', $this->lastAuthEntry()->description);
    }

    public function test_an_email_linked_to_another_google_account_is_logged_against_that_account(): void
    {
        GoogleWorkspaceDomain::create(['domain' => 'coachwoodgroup.com']);
        $owner = User::factory()->create(['email' => 'taken@coachwoodgroup.com', 'google_id' => 'google-owner']);
        $this->googleReturns('google-other', 'taken@coachwoodgroup.com', 'coachwoodgroup.com');

        $this->get('/auth/google/callback');

        $entry = $this->lastAuthEntry();
        $this->assertSame('Login blocked - linked to a different Google account', $entry->description);
        $this->assertTrue($entry->subject->is($owner));
        $this->assertNull($entry->causer_id);
    }

    public function test_logging_out_is_logged(): void
    {
        // The old README said logouts were logged; nothing logged them (found issue 24).
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertSame('Logged out', $this->lastAuthEntry()->description);
        $this->assertTrue($this->lastAuthEntry()->causer->is($user));
    }

    public function test_signing_out_a_disabled_user_is_logged(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);

        $this->actingAs($user)->get('/dashboard');

        $this->assertSame('Signed out - account disabled', $this->lastAuthEntry()->description);
        $this->assertTrue($this->lastAuthEntry()->causer->is($user));
    }
}
