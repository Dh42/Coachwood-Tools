{{--
    The VC and SC Listings Editors' page, ported from the old app's two
    near-identical views onto the new design. The channel decides the few
    differences: VC is keyed by ASIN and adds Brand, Mfr., RC, CDQ and LI
    columns and the Export All button; SC is keyed by SKU (one ASIN can have
    several seller SKUs) and adds Sync Products. Behaviour is in
    resources/js/tools/listings.js; page data is in #listings-config.
--}}
@php
    use App\Services\AmazonListings\AttributeEngine;
    use App\Services\AmazonListings\ListingChannel;
    use App\Support\ExternalWrites;

    $isVendor = $channel === ListingChannel::Vendor;
    $p = $isVendor ? 'vc' : 'sc';
    $tool = $isVendor ? 'vc-listings' : 'sc-listings';
    $noun = $isVendor ? __('ASIN') : __('SKU');
    $selectedKey = $isVendor ? $selectedAsin : $selectedSku;
    $isAdmin = auth()->user()?->hasRole('admin') ?? false;
    $writesEnabled = ExternalWrites::allowed('listings');
    $contributionMismatches = $products->filter(fn ($product) => $product->latestListingAudit && $product->latestListingAudit->contribution_mismatch_count > 0);

    $tourSteps = array_values(array_filter([
        $writesEnabled ? null : ['element' => "#{$p}-writes-off", 'intro' => __("Until cutover this app doesn't change anything on Amazon. Editing works as normal and Amazon still validates each change, but nothing is submitted.")],
        ['element' => "#{$p}-legend", 'intro' => __('What every column and symbol in the table means, and how the page works.')],
        $isAdmin ? ['element' => "#{$p}-config", 'intro' => __("Pick up to 7 attributes to show as columns, from every attribute this catalog's listings actually have. An alias only shortens the header. Only admins can change this.")] : null,
        $isVendor ? null : ['element' => '#sc-sync-btn', 'intro' => __("Sync Products refreshes this list of SKUs, titles and statuses from Amazon's all-listings report. The table reads from the last sync; the editor always loads live.")],
        ['element' => "#{$p}-audit-btn", 'intro' => __("Audit Listings re-checks every active listing's completeness, A+ status, image count, and whether Amazon's live listing still matches what we last submitted. It also runs daily at 08:30 UTC; the table always shows the last saved result.")],
        ['element' => "#{$p}-contribution-mismatches", 'intro' => __("Every listing whose live content no longer matches the last change we submitted and had accepted, for at least one attribute. Only shown when there's at least one.")],
        ['element' => "#{$p}-table-controls", 'intro' => $isVendor
            ? __('Search by title, ASIN or SKU. The filters narrow the table to contribution mismatches, missing A+ Content, open Listings issues, or a CDQ grade of C or D, without re-running the audit. Click a column header to sort; click again to reverse.')
            : __('Search by title, ASIN or SKU. The filters narrow the table to contribution mismatches or missing A+ Content, without re-running the audit. Click a column header to sort; click again to reverse.')],
        ['element' => "#{$p}-listings-table", 'intro' => $isVendor
            ? __("Click a title or the arrow to open that listing's live attributes from Amazon. Our Contribution says whether Amazon's live listing still matches our last accepted submission. RC is Amazon's Replenishment Category (hover for the full name). LI is Listings Items issues: 🟢 none reported at the last audit, 🔴 at least one open (hover for the text). Amazon doesn't report an issue for every suppression, so 🟢 isn't a guarantee.")
            : __("Click a title or the arrow to open that SKU's live attributes from Amazon. Our Contribution says whether Amazon's live listing still matches our last accepted submission.")],
        ['element' => '#expanded-content', 'intro' => __("Every attribute Amazon returns, not just title and bullets. Fields Amazon limits to a fixed set are dropdowns from Amazon's own schema. A field outlined in red has drifted from what we last submitted. Submitting runs Amazon's VALIDATION_PREVIEW first, and only a clean preview is followed by the real submission. Listing Issues and Restrictions, and the edit history, are below the form.")],
    ]));

    $pageConfig = [
        'rowAttr' => $isVendor ? 'asin' : 'sku',
        'auditBatchUrl' => route("tools.{$tool}.audit.batch"),
        'exportBatchUrl' => $isVendor ? route('tools.vc-listings.export.batch') : null,
        'exportColumns' => $exportColumns ?? [],
        'syncStartUrl' => $isVendor ? null : route('tools.sc-listings.sync.start'),
        'syncPollUrl' => $isVendor ? null : route('tools.sc-listings.sync.poll'),
        't' => [
            'loading' => __('Loading the live listing from Amazon…'),
            'loadFailed' => __("Couldn't load this listing. Try again in a minute."),
            'auditFailed' => __('The audit stopped partway through. Rows already checked are saved; run it again to finish.'),
            'lastFullAudit' => __('Last full audit: :time.'),
            'exportFailed' => __('Export failed partway through — try again.'),
            'syncRequesting' => __('Requesting the report from Amazon…'),
            'syncWaiting' => __('Waiting for Amazon to finish the report…'),
            'synced' => __('Synced :count listing(s). Reloading…'),
            'syncFailed' => __('Sync failed: :message'),
            'rowsShown' => __(':shown of :total listings'),
        ],
    ];
@endphp
<x-layouts.app :title="$isVendor ? __('VC Listings Editor') : __('SC Listings Editor')" :breadcrumb="__('Amazon · Listings')" :flash-inline="true">
    <x-slot:subtitle>
        {{ $isVendor
            ? __('Edit live Amazon.ca (Vendor Central) listing attributes through the SP-API. Every field Amazon returns for an ASIN is editable here, validated by Amazon before every submission, and logged.')
            : __('Edit live Amazon.com (Seller Central) listing attributes through the SP-API. Every field Amazon returns for a SKU is editable here, validated by Amazon before every submission, and logged.') }}
    </x-slot:subtitle>
    <x-slot:actions>
        <x-tour-button id="{{ $p }}-tour-btn" :steps="$tourSteps" />
        @if ($isVendor)
            <button type="button" class="btn btn-sm" id="vc-export-btn"><x-icon name="csv" size="sm" />{{ __('Export All (.csv)') }}</button>
        @else
            <form method="post" action="{{ route('tools.sc-listings.sync') }}" id="sc-sync-form">
                @csrf
                <button type="submit" class="btn btn-sm" id="sc-sync-btn"><x-icon name="download" size="sm" />{{ __('Sync Products') }}</button>
            </form>
        @endif
        <form method="post" action="{{ route("tools.{$tool}.audit.run") }}" id="{{ $p }}-audit-form">
            @csrf
            <button type="submit" class="btn btn-sm btn-primary" id="{{ $p }}-audit-btn"><x-icon name="check" size="sm" />{{ __('Audit Listings') }}</button>
        </form>
    </x-slot:actions>

    <x-flash-notices />

    @unless ($writesEnabled)
        <x-notice tone="warn" id="{{ $p }}-writes-off" :title="__('Submissions are off in this app until cutover.')">
            {{ __("You can open and edit any listing, and Validate runs Amazon's own validation preview, but nothing is submitted to Amazon and nothing is logged. Make real changes in the old app for now.") }}
        </x-notice>
    @endunless

    <div id="{{ $p }}-progress" class="listing-progress-group" aria-live="polite">
        <div class="listing-progress" id="{{ $p }}-audit-progress" hidden>
            <div class="flex justify-between text-sm"><span>{{ __('Auditing…') }}</span><span id="{{ $p }}-audit-progress-count"></span></div>
            <progress id="{{ $p }}-audit-progress-bar" max="100" value="0" aria-label="{{ __('Audit progress') }}"></progress>
        </div>
        @if ($isVendor)
            <div class="listing-progress" id="vc-export-progress" hidden>
                <div class="flex justify-between text-sm"><span>{{ __('Exporting — this fetches every active listing live, so it can take a minute…') }}</span><span id="vc-export-progress-count"></span></div>
                <progress id="vc-export-progress-bar" max="100" value="0" aria-label="{{ __('Export progress') }}"></progress>
            </div>
        @else
            <div class="listing-progress" id="sc-sync-progress" hidden>
                <div class="text-sm" id="sc-sync-progress-label">{{ __('Syncing…') }}</div>
                {{-- Amazon's reports have no progress, only "processing" until done, so this bar is indeterminate. --}}
                <progress aria-label="{{ __('Sync progress') }}"></progress>
            </div>
        @endif
    </div>

    <details class="card scope-card" id="{{ $p }}-legend">
        <summary class="card-body font-semibold">{{ __('Listing key and instructions') }}</summary>
        <dl class="scope-list">
            <dt>✅ / ❌</dt>
            <dd>{{ __('Present or missing, for whichever (up to 7) attributes are configured as columns.') }}</dd>
            <dt>@include('tools.listings._aplus-badge', ['status' => 'full'])</dt>
            <dd>{{ __('Product-specific A+ Content exists.') }}</dd>
            <dt>@include('tools.listings._aplus-badge', ['status' => 'brand_only'])</dt>
            <dd>{{ __('Only a shared Brand Story banner, nothing specific to this product.') }}</dd>
            <dt>@include('tools.listings._aplus-badge', ['status' => 'none'])</dt>
            <dd>{{ __('No A+ Content of any kind.') }}</dd>
            <dt><x-status-badge tone="good">{{ __('Matches') }}</x-status-badge></dt>
            <dd>{{ __("Our Contribution: Amazon's live listing still matches everything we last submitted and had accepted.") }}</dd>
            <dt><x-status-badge tone="bad">{{ __('N mismatched') }}</x-status-badge></dt>
            <dd>{{ __('That many attributes have drifted from our last accepted submission. Checked through the SP-API against our own submission history, never a scrape.') }}</dd>
            <dt>—</dt>
            <dd>{{ __("Never audited; or, for Our Contribution, we've never had an accepted submission for this :noun, so there's nothing of ours to check.", ['noun' => $noun]) }}</dd>
            @if ($isVendor)
                <dt>{{ __('RC') }}</dt>
                <dd>{{ __("Replenishment Category, from Amazon's catalog: NP New Product, PR Planned Replenishment, R Replenishable, NR Non-Replenishable, O Obsolete, A Adhoc. Hover a value for the full name.") }}</dd>
                <dt>{{ __('CDQ') }}</dt>
                <dd>{{ __("Our content-quality grade from the CDQ Listing Quality Report; click it for the defect breakdown.") }}</dd>
                <dt>{{ __('LI') }} 🟢 / 🔴</dt>
                <dd>{{ __("Listings Items issues: none open at the last audit, or at least one open (hover for the text, including severity and any enforcement such as LISTING_SUPPRESSED). Amazon doesn't report an issue for every suppression, so 🟢 means nothing was reported, not that nothing is wrong.") }}</dd>
            @endif
            <dt>{{ __('Using the page') }}</dt>
            <dd>
                <ol class="list-decimal pl-5">
                    @unless ($isVendor)
                        <li>{{ __("Click Sync Products for the newest SKUs and statuses from Amazon. The table reads from the last sync until you open a listing.") }}</li>
                    @endunless
                    <li>{{ $isVendor
                        ? __("Click a title or the arrow to open that ASIN's live attributes from the SP-API.")
                        : __("Click a title or the arrow to open that SKU's live attributes from the SP-API. One ASIN can have more than one SKU; each is its own row.") }}</li>
                    <li>{{ __("Every attribute Amazon returns is editable, not just title and bullets. Language tags are shown for context, never edited.") }}</li>
                    <li>{{ __("Nutrition Facts has its own section, built from the product type's schema, since it's often blank and its fields vary by product type.") }}</li>
                    <li>{{ __("Submitting runs Amazon's validation preview first; only a clean preview is followed by the real submission. Every submission is logged in the edit history below the form, with Amazon's answer.") }}</li>
                    <li>{{ __('Audit Listings re-checks every active listing. It runs daily too, and the table always shows the last saved result, so there is no need to re-run it just to look.') }}</li>
                </ol>
            </dd>
        </dl>
    </details>

    @if ($isAdmin)
        <details class="card scope-card" id="{{ $p }}-config">
            <summary class="card-body font-semibold"><span class="inline-flex items-center gap-2">{{ __('Configure audit columns') }} <x-status-badge tone="mute" :icon="false">{{ __('Admins') }}</x-status-badge></span></summary>
            <form method="post" action="{{ route("tools.{$tool}.audit.config") }}" class="card-body pt-0 form-stack">
                @csrf
                <p class="muted text-sm">{{ __("Pick up to 7 attributes to show as columns, from every attribute this catalog's listings actually have. An alias shows a shorter name in the table header; it doesn't change what is audited.") }}</p>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @for ($i = 1; $i <= 7; $i++)
                        <div class="field">
                            <label for="{{ $p }}-slot-{{ $i }}">{{ __('Column :n', ['n' => $i]) }}</label>
                            <select class="select" id="{{ $p }}-slot-{{ $i }}" name="attr_slot_{{ $i }}">
                                <option value="">—</option>
                                @foreach ($availableAttributes as $key => $label)
                                    <option value="{{ $key }}" {{ ($auditRawSlots[$i - 1] ?? null) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="text" class="input" name="alias_slot_{{ $i }}" maxlength="40" placeholder="{{ __('Alias (optional)') }}" aria-label="{{ __('Alias for column :n', ['n' => $i]) }}" value="{{ $auditRawAliases[$i - 1] ?? '' }}">
                        </div>
                    @endfor
                </div>
                <div class="field">
                    <label for="{{ $p }}-config-reason">{{ __('Reason (optional)') }}</label>
                    <input type="text" class="input" id="{{ $p }}-config-reason" name="config_reason" maxlength="255">
                </div>
                <div class="form-actions"><button type="submit" class="btn">{{ __('Save column settings') }}</button></div>
            </form>
        </details>
    @endif

    @if ($contributionMismatches->isNotEmpty())
        <x-card id="{{ $p }}-contribution-mismatches" class="card-tone-bad" :title="__('Not showing our contribution — :n product(s)', ['n' => $contributionMismatches->count()])">
            <p class="muted text-sm">{{ __("Amazon's live listing no longer matches the last edit we submitted and had accepted, for at least one attribute. Checked through the SP-API (getListingsItem) against our own submission history, not a scrape.") }}</p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Product') }}</th>
                            <th scope="col">{{ __('ASIN') }}</th>
                            @unless ($isVendor)<th scope="col">{{ __('SKU') }}</th>@endunless
                            <th scope="col">{{ __('Attribute(s) drifted') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contributionMismatches as $product)
                            <tr>
                                <td class="wrap-cell"><a href="{{ route("tools.{$tool}.index", $isVendor ? ['asin' => $product->asin] : ['sku' => $product->sku]).'#row-'.$product->id }}">{{ $product->title }}</a></td>
                                <td class="mono">{{ $product->asin }}</td>
                                @unless ($isVendor)<td class="mono">{{ $product->sku }}</td>@endunless
                                <td class="mono">{{ str_replace(',', ', ', $product->latestListingAudit->contribution_mismatch_attrs) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    <x-card :title="__('Pick a listing')" id="{{ $p }}-listings">
        <p class="muted text-sm">
            {{ trans_choice(':count active listing.|:count active listings.', $products->count(), ['count' => $products->count()]) }}
            {{ __('Columns show the last saved audit; — means never audited.') }}
            <span id="{{ $p }}-last-full-audit">@if ($lastFullAudit){{ __('Last full audit: :time.', ['time' => $lastFullAudit->format('Y-m-d H:i T')]) }}@endif</span>
        </p>

        <div class="table-tools" id="{{ $p }}-table-controls">
            <div class="flex flex-wrap items-center gap-2">
                <label class="sr-only" for="{{ $p }}-search">{{ __('Search listings') }}</label>
                <input type="search" id="{{ $p }}-search" class="input" placeholder="{{ __('Search by product, ASIN or SKU') }}">
                <div class="segmented segmented-wrap" role="group" aria-label="{{ __('Show') }}" data-filter-group>
                    <button type="button" data-filter="all" aria-pressed="true">{{ __('All') }}</button>
                    <button type="button" data-filter="mismatch" aria-pressed="false">{{ __('Contribution mismatch') }}</button>
                    <button type="button" data-filter="noaplus" aria-pressed="false">{{ __('Missing A+') }}</button>
                    @if ($isVendor)
                        <button type="button" data-filter="issues" aria-pressed="false">{{ __('Has issues') }}</button>
                        <button type="button" data-filter="cdq-attention" aria-pressed="false">{{ __('CDQ needs attention') }}</button>
                    @endif
                </div>
                <span class="label" data-rows-status aria-live="polite"></span>
            </div>
        </div>

        @if ($catalogError)
            <x-notice tone="bad" :title="__('Couldn\'t load this listing.')">{{ $catalogError }}</x-notice>
        @endif

        <div class="table-wrap listings-table" id="{{ $p }}-listings-table">
            <table class="table">
                <caption class="sr-only">{{ __('Active listings with their last audit') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Product') }}</button></th>
                        @foreach ($auditConfig as $slotKey)
                            <th scope="col" data-sort><button type="button" class="sort-btn">{{ $auditAliases[$slotKey] ?? AttributeEngine::displayLabel($availableAttributes[$slotKey] ?? AttributeEngine::humanizeKey($slotKey)) }}</button></th>
                        @endforeach
                        @if ($isVendor)
                            <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Brand') }}</button></th>
                            <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Mfr.') }}</button></th>
                        @endif
                        <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('A+ Content') }}</button></th>
                        <th scope="col" data-sort class="num"><button type="button" class="sort-btn">{{ __('Images') }}</button></th>
                        <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Our Contribution') }}</button></th>
                        @if ($isVendor)
                            <th scope="col" data-sort title="{{ __('Replenishment Category') }}"><button type="button" class="sort-btn">{{ __('RC') }}</button></th>
                            <th scope="col" data-sort title="{{ __('Content-quality grade; click a grade for the defect breakdown') }}"><button type="button" class="sort-btn">{{ __('CDQ') }}</button></th>
                            <th scope="col" data-sort title="{{ __('Listings Items issues') }}"><button type="button" class="sort-btn">{{ __('LI') }}</button></th>
                        @endif
                        <th scope="col" data-sort><button type="button" class="sort-btn">{{ __('Last Checked') }}</button></th>
                        <th scope="col"><span class="sr-only">{{ __('Edit') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        @php $isSelected = ($isVendor ? $product->asin : $product->sku) === $selectedKey; @endphp
                        @include('tools.listings._product-row', ['isSelected' => $isSelected, 'showListingIssues' => $isVendor, 'cdqByAsin' => $cdqByAsin ?? []])
                        @if ($isSelected && $editorData)
                            <tr class="editor-row" id="editor-row">
                                <td colspan="100">
                                    <div id="expanded-content">
                                        @include('tools.listings._editor', $editorData + ['submitRoute' => route("tools.{$tool}.submit")])
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="100"><x-empty-state :title="__('No active listings')" icon="inbox">{{ $isVendor ? __('Products are copied from the old app daily.') : __('Click Sync Products to load them from Amazon.') }}</x-empty-state></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <script type="application/json" id="listings-config">@json($pageConfig)</script>
    @vite('resources/js/tools/listings.js')
</x-layouts.app>
