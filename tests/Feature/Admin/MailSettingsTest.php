<?php

namespace Tests\Feature\Admin;

use App\Models\GmailConnection;
use App\Models\User;
use App\Notifications\GmailConnectionTestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Admin > Mail settings and the Gmail API transport. No test reaches Google: every request is faked. */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create()->assignRole('admin');
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    private function connect(): GmailConnection
    {
        return GmailConnection::create(['email' => 'tools@example.test', 'refresh_token' => 'refresh-1', 'connected_by' => $this->admin->id]);
    }

    public function test_the_page_offers_to_connect_when_nothing_is_connected(): void
    {
        $this->actingAs($this->admin)->get(route('admin.mail.show'))
            ->assertOk()
            ->assertSee('Not connected yet')
            ->assertSee('href="'.route('admin.mail.connect').'"', false);
    }

    public function test_the_page_names_the_connected_account_and_who_connected_it(): void
    {
        $this->connect();

        $this->actingAs($this->admin)->get(route('admin.mail.show'))
            ->assertSee('tools@example.test')
            ->assertSee($this->admin->name)
            ->assertSee('action="'.route('admin.mail.test').'"', false);
    }

    public function test_the_sidebar_links_mail_settings_for_admins(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertSee('href="'.route('admin.mail.show').'"', false);
    }

    public function test_connecting_asks_google_for_send_only_access_that_lasts(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('redirectUrl')->with(route('admin.mail.callback'))->once()->andReturnSelf();
        $driver->shouldReceive('scopes')->with(['https://www.googleapis.com/auth/gmail.send'])->once()->andReturnSelf();
        $driver->shouldReceive('with')->with(['access_type' => 'offline', 'prompt' => 'consent'])->once()->andReturnSelf();
        $driver->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/fake'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->actingAs($this->admin)->get(route('admin.mail.connect'))->assertRedirect('https://accounts.google.com/fake');
    }

    public function test_the_callback_replaces_the_connection_stores_the_token_encrypted_and_is_logged(): void
    {
        $this->connect();
        Cache::put('gmail_api_access_token', 'stale', 3300);

        $googleUser = SocialiteUser::fake(['id' => 'g-1', 'email' => 'sender@example.test'])->setRefreshToken('refresh-2');
        $driver = Mockery::mock();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->actingAs($this->admin)->get(route('admin.mail.callback'))->assertRedirect(route('admin.mail.show'));

        $connection = GmailConnection::sole();
        $this->assertSame('sender@example.test', $connection->email);
        $this->assertSame('refresh-2', $connection->refresh_token);
        $this->assertNotSame('refresh-2', DB::table('gmail_connections')->value('refresh_token'));
        $this->assertFalse(Cache::has('gmail_api_access_token'));
        $this->assertSame('Gmail sending account connected', Activity::query()->latest('id')->value('description'));
    }

    public function test_the_callback_explains_a_missing_refresh_token(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn(SocialiteUser::fake(['id' => 'g-1', 'email' => 'sender@example.test'])->setRefreshToken(null));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->actingAs($this->admin)->get(route('admin.mail.callback'))->assertSessionHasErrors('mail');
        $this->assertSame(0, GmailConnection::count());
    }

    public function test_the_transport_sends_through_the_gmail_api_with_a_refreshed_token(): void
    {
        $this->connect();
        config(['mail.default' => 'gmail_api']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1']),
            'www.googleapis.com/gmail/*' => Http::response(['id' => 'm-1']),
        ]);

        Mail::raw('Hello', fn ($message) => $message->to('someone@example.test')->subject('Hi'));

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token' && $request['refresh_token'] === 'refresh-1');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/gmail/v1/users/me/messages/send')
            && $request->hasHeader('Authorization', 'Bearer access-1')
            && str_contains(base64_decode(strtr($request['raw'], '-_', '+/')), 'Subject: Hi'));
    }

    /** Found issue 19: a revoked token stayed cached for up to 55 minutes, so every send failed. */
    public function test_a_rejected_cached_token_is_dropped_and_the_send_retried_once(): void
    {
        $this->connect();
        config(['mail.default' => 'gmail_api']);
        Cache::put('gmail_api_access_token', 'revoked', 3300);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh']),
            'www.googleapis.com/gmail/*' => Http::sequence()->push(['error' => ['code' => 401]], 401)->push(['id' => 'm-1']),
        ]);

        Mail::raw('Hello', fn ($message) => $message->to('someone@example.test')->subject('Hi'));

        Http::assertSentCount(3);
        $this->assertSame('fresh', Cache::get('gmail_api_access_token'));
    }

    /** Found issue 19: the test-mail button returned a 500 when Gmail failed. */
    public function test_a_failed_test_email_says_what_went_wrong_instead_of_a_500(): void
    {
        config(['mail.default' => 'gmail_api']); // nothing connected, so the transport throws

        $this->actingAs($this->admin)->post(route('admin.mail.test'))
            ->assertRedirect(route('admin.mail.show'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Mail Settings'));
    }

    public function test_a_test_email_goes_to_the_admin_who_asked(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('admin.mail.test'))->assertSessionHas('status');

        Notification::assertSentTo($this->admin, GmailConnectionTestNotification::class);
    }

    public function test_mail_settings_are_for_admins_only(): void
    {
        $user = User::factory()->create()->assignRole('user');

        $this->actingAs($user)->get(route('admin.mail.show'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.mail.test'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.mail.connect'))->assertForbidden();
    }
}
