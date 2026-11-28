<?php

namespace Tests\Unit\Services\TeamSponsorship;

use App\Services\Klaviyo\KlaviyoClient;
use App\Services\TeamSponsorship\KlaviyoSponsorshipNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KlaviyoSponsorshipNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            // These tests check what the real write sends; the suite runs
            // with writes off (Tests\TestCase), so they switch it on.
            'tools.external_writes' => true,
            'klaviyo.accounts.BS' => ['name' => 'BioSteel', 'private_api_key' => 'test-bs-key'],
            'klaviyo.events.claimed_sponsorship_package' => 'Claimed Sponsorship Package',
        ]);
    }

    private function notifier(): KlaviyoSponsorshipNotifier
    {
        return new KlaviyoSponsorshipNotifier(new KlaviyoClient('BS'));
    }

    public function test_sends_the_claimed_sponsorship_package_event_with_order_details(): void
    {
        Http::fake(['a.klaviyo.com/*' => Http::response('', 202)]);

        $this->notifier()->notifyClaimed('shopper@example.com', 'New Team', 'HOCKEY', [
            'draft_order_id' => 999888,
            'order_name' => '#D1',
            'admin_url' => 'https://test-store.myshopify.com/admin/draft_orders/999888',
            'line_items' => [['label' => 'Bottles', 'quantity' => 2]],
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $attrs = $request['data']['attributes'];

            return $request->url() === 'https://a.klaviyo.com/api/events/'
                && $attrs['metric']['data']['attributes']['name'] === 'Claimed Sponsorship Package'
                && $attrs['profile']['data']['attributes']['email'] === 'shopper@example.com'
                && $attrs['properties']['team_name'] === 'New Team'
                && $attrs['properties']['sport'] === 'HOCKEY'
                && $attrs['properties']['order_id'] === 999888
                && $attrs['properties']['order_name'] === '#D1'
                && $attrs['properties']['line_items'] === [['label' => 'Bottles', 'quantity' => 2]];
        });
    }

    public function test_skips_silently_when_klaviyo_is_not_configured(): void
    {
        config(['klaviyo.accounts.BS.private_api_key' => null]);
        Http::fake();

        $this->notifier()->notifyClaimed('shopper@example.com', 'New Team', null, ['draft_order_id' => 999888]);

        Http::assertNothingSent();
    }
}
