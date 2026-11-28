{{--
    VC Image Manager, ported from the old app onto the new design. Behaviour
    is in resources/js/tools/image-manager.js; page data is in
    #image-manager-config.
--}}
@php
    $unattributedProducts = $products->filter(fn ($product) => $product->latestImageAudit && $product->latestImageAudit->unattributed_count > 0);
    $notLiveProducts = $products->filter(fn ($product) => $product->latestImageAudit && $product->latestImageAudit->not_live_count > 0);

    $tourSteps = array_values(array_filter([
        $writesEnabled ? null : ['element' => '#img-writes-off', 'intro' => __("Until cutover this app doesn't change anything on Amazon. Uploads and removals are validated by Amazon, but nothing is submitted.")],
        ['element' => '#img-legend', 'intro' => __("Worth opening once: what the Live, Not live and Not ours statuses mean, and what we know (and don't) about whose image Amazon shows when several parties submit one.")],
        ['element' => '#img-unattributed-summary', 'intro' => __("Products with at least one live image slot that isn't our contribution: never uploaded through this tool, or changed since we last uploaded it. Only shown when there's at least one.")],
        ['element' => '#img-not-live-summary', 'intro' => __("Products where we've submitted an image for a slot but it isn't showing live (Amazon's own wording is \"Not live\"). Only shown when there's at least one.")],
        ['element' => '#img-audit-btn', 'intro' => __("Audit Images re-checks every active product's 9 image slots (Main and Other 1 to 8) against Amazon's live listing data. The table always shows the last saved result, so it's fine to leave it a while.")],
        ['element' => '#img-products-table', 'intro' => __("Click a title or the arrow to load that product's live images from Amazon.")],
        ['element' => '#image-grid', 'intro' => __("One box per image slot, with what's live beside what we last submitted, and its status. Pick a file and click Upload or Replace: Amazon validates it first, and only a clean validation is followed by the real submission. Remove clears the slot, after you confirm. Every submission is logged in the VC Listings Editor's edit history for the product.")],
    ]));

    $pageConfig = [
        'auditBatchUrl' => route('tools.vc-image-manager.audit.batch'),
        't' => [
            'loading' => __("Loading the product's images from Amazon…"),
            'loadFailed' => __("Couldn't load this product's images. Try again in a minute."),
            'auditFailed' => __('The audit stopped partway through. Products already checked are saved; run it again to finish.'),
            'lastFullAudit' => __('Last full audit: :time.'),
            'rowsShown' => __(':shown of :total products'),
            'removeTitle' => __('Remove the :slot image?'),
            'uploading' => __('Sending to Amazon…'),
        ],
    ];
@endphp
<x-layouts.app :title="__('VC Image Manager')" :breadcrumb="__('Amazon · Listings')" :flash-inline="true">
    <x-slot:subtitle>{{ __("Upload, replace and remove Vendor Central product images without Amazon's own interface. Amazon validates every change before it is submitted.") }}</x-slot:subtitle>
    <x-slot:actions>
        <x-tour-button id="img-tour-btn" :steps="$tourSteps" />
        <form method="post" action="{{ route('tools.vc-image-manager.audit.run') }}" id="img-audit-form">
            @csrf
            <button type="submit" class="btn btn-sm btn-primary" id="img-audit-btn"><x-icon name="check" size="sm" />{{ __('Audit Images') }}</button>
        </form>
    </x-slot:actions>

    <x-flash-notices />

    @unless ($writesEnabled)
        <x-notice tone="warn" id="img-writes-off" :title="__('Submissions are off in this app until cutover.')">
            {{ __("Upload, Replace and Remove run Amazon's own validation, but nothing is submitted to Amazon, nothing is logged, and an uploaded file isn't kept. Change images in the old app for now.") }}
        </x-notice>
    @endunless

    <div class="listing-progress-group" aria-live="polite">
        <div class="listing-progress" id="img-audit-progress" hidden>
            <div class="flex justify-between text-sm"><span>{{ __('Auditing…') }}</span><span id="img-audit-progress-count"></span></div>
            <progress id="img-audit-progress-bar" max="100" value="0" aria-label="{{ __('Audit progress') }}"></progress>
        </div>
    </div>

    <details class="card scope-card" id="img-legend">
        <summary class="card-body font-semibold">{{ __('Image status key and instructions') }}</summary>
        <dl class="scope-list">
            <dt><x-status-badge tone="good">{{ __('Live') }}</x-status-badge></dt>
            <dd>{{ __('Our last accepted submission matches what Amazon shows live for that slot.') }}</dd>
            <dt><x-status-badge tone="warn">{{ __('Not live') }}</x-status-badge></dt>
            <dd>{{ __("We have an accepted submission for that slot, but it doesn't match what's live. Another party's image may have precedence, or Amazon hasn't shown ours yet (it can take up to 24 hours).") }}</dd>
            <dt><x-status-badge tone="bad">{{ __('Not ours') }}</x-status-badge></dt>
            <dd>{{ __("Something is live in that slot, but we've never submitted anything for it through this tool.") }}</dd>
            <dt>—</dt>
            <dd>{{ __('Nothing live and nothing submitted, or never audited.') }}</dd>
            <dt>{{ __('Whose image wins') }}</dt>
            <dd>{{ __("Amazon's API has no ownership or precedence field: nothing in the live listing says who contributed an image or why it was chosen. Brand-registered Vendor Central content generally carries the most weight, but Amazon's choice between competing submissions is otherwise undocumented. Live and Not live are the closest signal: whether our own submission currently matches.") }}</dd>
            <dt>{{ __('Other slots') }}</dt>
            <dd>{{ __("Vendor Central's own interface also shows FRNT, SIDE and BACK slots. Amazon's API can't read or write those at all (an open Amazon feature request), so they aren't managed here.") }}</dd>
        </dl>
    </details>

    @if ($unattributedProducts->isNotEmpty())
        <x-card id="img-unattributed-summary" class="card-tone-bad" :title="__('Not showing our contribution — :n product(s)', ['n' => $unattributedProducts->count()])">
            <p class="muted text-sm">{{ __('At least one live image slot on these products was never submitted through this tool, or no longer matches the last image we submitted and had accepted. Checked through the SP-API (getListingsItem) against our own submission history, not a scrape.') }}</p>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('ASIN') }}</th><th scope="col">{{ __('Slots not ours') }}</th></tr></thead>
                    <tbody>
                        @foreach ($unattributedProducts as $product)
                            <tr>
                                <td class="wrap-cell"><a href="{{ route('tools.vc-image-manager.index', ['asin' => $product->asin]).'#row-'.$product->id }}">{{ $product->title }}</a></td>
                                <td class="mono">{{ $product->asin }}</td>
                                <td class="mono">{{ str_replace(',', ', ', $product->latestImageAudit->unattributed_variants) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    @if ($notLiveProducts->isNotEmpty())
        <x-card id="img-not-live-summary" class="card-tone-warn" :title="__('Submitted but not live — :n product(s)', ['n' => $notLiveProducts->count()])">
            <p class="muted text-sm">{{ __("We have an accepted submission for at least one image slot on these products, but it isn't showing live: another party's image may have precedence, or Amazon hasn't shown ours yet.") }}</p>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('ASIN') }}</th><th scope="col">{{ __('Slots not live') }}</th></tr></thead>
                    <tbody>
                        @foreach ($notLiveProducts as $product)
                            <tr>
                                <td class="wrap-cell"><a href="{{ route('tools.vc-image-manager.index', ['asin' => $product->asin]).'#row-'.$product->id }}">{{ $product->title }}</a></td>
                                <td class="mono">{{ $product->asin }}</td>
                                <td class="mono">{{ str_replace(',', ', ', $product->latestImageAudit->not_live_variants) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    <x-card :title="__('Pick a product')" id="img-products">
        <p class="muted text-sm">
            {{ trans_choice(':count active product.|:count active products.', $products->count(), ['count' => $products->count()]) }}
            {{ __('The status columns show the last saved audit; — means never audited.') }}
            <span id="img-last-full-audit">@if ($lastFullAudit){{ __('Last full audit: :time.', ['time' => $lastFullAudit->format('Y-m-d H:i T')]) }}@endif</span>
        </p>

        <div class="table-tools">
            <div class="flex flex-wrap items-center gap-2">
                <label class="sr-only" for="img-search">{{ __('Search products') }}</label>
                <input type="search" id="img-search" class="input" placeholder="{{ __('Search by product, ASIN or SKU') }}">
                <span class="label" data-rows-status aria-live="polite"></span>
            </div>
        </div>

        @if ($catalogError)
            <x-notice tone="bad" :title="__('Couldn\'t load this product\'s images.')">{{ $catalogError }}</x-notice>
        @endif

        <div class="table-wrap listings-table" id="img-products-table">
            <table class="table">
                <caption class="sr-only">{{ __('Active products with their last image audit') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Product') }}</button></th>
                        <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Not Ours') }}</button></th>
                        <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Not Live') }}</button></th>
                        <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Last Checked') }}</button></th>
                        <th scope="col"><span class="sr-only">{{ __('Images') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        @php $isSelected = $product->asin === $selectedAsin; @endphp
                        @include('tools.image-manager._product-row', ['isSelected' => $isSelected])
                        @if ($isSelected && $gridData)
                            <tr class="editor-row" id="editor-row">
                                <td colspan="100">
                                    <div id="expanded-content">
                                        @include('tools.image-manager._grid', $gridData + [
                                            'uploadRoute' => route('tools.vc-image-manager.upload'),
                                            'removeRoute' => route('tools.vc-image-manager.remove'),
                                        ])
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="100"><x-empty-state :title="__('No active products')" icon="inbox">{{ __('Products are copied from the old app daily.') }}</x-empty-state></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-modal id="img-remove-modal" :title="__('Remove this image?')">
        <p class="text-sm" id="img-remove-text">{{ __("Amazon validates the removal first. With submissions on, the slot is then cleared on the live listing.") }}</p>
        <x-slot:footer>
            <button type="button" class="btn btn-sm" data-modal-close>{{ __('Cancel') }}</button>
            <button type="button" class="btn btn-sm btn-danger" id="img-remove-confirm">{{ __('Remove') }}</button>
        </x-slot:footer>
    </x-modal>

    <script type="application/json" id="image-manager-config">@json($pageConfig)</script>
    @vite('resources/js/tools/image-manager.js')
</x-layouts.app>
