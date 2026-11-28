{{--
    BioSteel Team Sponsorship, ported from the old app onto the new design.
    The search, the order dialog and the order request are in
    resources/js/tools/team-sponsorship.js; page data is in #ts-config.
--}}
@php
    $canSeeStats = auth()->user()?->can('tool.team-sponsorship-stats');
    $tourSteps = array_values(array_filter([
        $writesEnabled ? null : ['element' => '#ts-writes-off', 'intro' => __("Until cutover this app doesn't send anything to Shopify or Klaviyo. Searching works as normal; Create Order shows what it would have sent instead of sending it.")],
        ['element' => '#ts-search-card', 'intro' => __("Search by the team owner's email. This pulls every team registered to that email live from the BioSteel Teams API; nothing is stored. Each team also shows its sport, matched by admin email against a separate BioSteel endpoint, since team names alone can collide across owners.")],
        ['element' => '#ts-inventory-card', 'intro' => __('Live Shopify stock for the four sponsorship products: bottles, sample packs, towels and (for hockey teams only) pucks. Refreshed on every page load.')],
        ['element' => '#sponsorshipResults', 'intro' => __('Search results appear here: each team\'s bottle count and a "Create Order" button, which opens a dialog to confirm the quantity and creates a free draft order in Shopify (bottles, sample packs, a towel per 5 bottles up to 3, and a hockey puck per bottle for hockey teams), then emails the coach the invoice. "Possible fraud" means the bottle count is well above the team\'s registered emails: worth a second look. A team that already has an order shows its Shopify link instead. "View team response" shows the raw API data.')],
        $canSeeStats ? ['element' => '#ts-stats-link', 'intro' => __("Totals, monthly charts and the full order history, built from this tool's own order records.")] : null,
    ]));
    $pageConfig = [
        'searchUrl' => route('tools.team-sponsorship.search'),
        'orderUrl' => route('tools.team-sponsorship.orders.store'),
        'email' => $email,
        't' => [
            'invalidEmail' => __('Please enter a valid email address.'),
            'failed' => __('Something went wrong. Please try again.'),
            'created' => __('Draft order created:'),
            'emailed' => __('invoice emailed.'),
            'notEmailed' => __('order created, but the invoice email failed to send.'),
            'orderFailed' => __('Failed to create order.'),
        ],
    ];
@endphp
<x-layouts.app :title="__('BioSteel Team Sponsorship')" :breadcrumb="__('BioSteel Teams')">
    <x-slot:subtitle>{{ __("Look up a team's sponsorship eligibility through the BioSteel Teams API and send their free sponsorship package through Shopify.") }}</x-slot:subtitle>
    <x-slot:actions>
        <x-tour-button id="ts-tour-btn" :steps="$tourSteps" />
        @if ($canSeeStats)
            <a href="{{ route('tools.team-sponsorship.stats') }}" class="btn btn-sm" id="ts-stats-link"><x-icon name="pie" size="sm" />{{ __('Stats') }}</a>
        @endif
    </x-slot:actions>

    @unless ($writesEnabled)
        <x-notice tone="warn" id="ts-writes-off" :title="__('Orders are off in this app until cutover.')">
            {{ __('Search works as normal, but Create Order sends nothing to Shopify or Klaviyo: it shows what the order would have contained. Send real sponsorships from the old app for now.') }}
        </x-notice>
    @endunless

    <x-card id="ts-search-card">
        <form id="searchForm" class="filter-bar" novalidate>
            <div class="field">
                <label for="email">{{ __('Team owner or contact email') }}</label>
                <input type="email" id="email" name="email" class="input" value="{{ $email }}" placeholder="coach@example.com" required autofocus aria-describedby="searchError">
                <p class="field-error" id="searchError" hidden></p>
            </div>
            <button type="submit" class="btn btn-primary" id="searchSubmit">
                <x-icon name="search" size="sm" /><span>{{ __('Get Teams') }}</span>
            </button>
        </form>
    </x-card>

    <x-card id="ts-inventory-card" :title="__('Current sponsorship inventory')">
        @if ($inventoryError)
            <x-notice tone="bad" :title="__('Couldn\'t load stock from Shopify.')">{{ $inventoryError }}</x-notice>
        @else
            <div class="stat-row stat-row-plain">
                @foreach ($inventory as $item)
                    <x-stat-tile :label="$item['label']" :value="is_int($item['inventory']) ? number_format($item['inventory']) : $item['inventory']"
                                 :sub="$item['title'].' · '.__('SKU').' '.$item['sku']" />
                @endforeach
            </div>
        @endif
    </x-card>

    <div id="sponsorshipResults" class="flex flex-col gap-4" aria-live="polite">
        @include('tools.team-sponsorship._results')
    </div>

    <x-modal id="ts-order-modal" :title="__('Create the draft order')">
        <form id="createOrderForm" class="form-stack">
            <p class="font-semibold" id="createOrderTeamName"></p>
            <input type="hidden" id="createOrderTeamNameInput">
            <input type="hidden" id="createOrderSportInput">
            <div class="field">
                <label for="createOrderQuantity">{{ __('Bottles') }}</label>
                <input type="number" min="1" id="createOrderQuantity" class="input" required>
                <p class="field-hint">{{ __('Sample packs match the bottles; one team towel per 5 bottles, up to 3.') }}</p>
            </div>
            <x-notice tone="info" id="createOrderPuckNote" hidden>{{ __('Hockey team: hockey pucks are included, one per bottle.') }}</x-notice>
            <div id="createOrderMessage" hidden></div>
            <div class="modal-foot">
                <button type="button" class="btn" data-modal-close>{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" id="createOrderSubmit">{{ __('Create Draft Order') }}</button>
            </div>
        </form>
    </x-modal>

    <script type="application/json" id="ts-config">@json($pageConfig)</script>
    @vite('resources/js/tools/team-sponsorship.js')
</x-layouts.app>
