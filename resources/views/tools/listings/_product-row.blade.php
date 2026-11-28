{{--
    One row of the listings table, shared by the page and the audit batch
    response (which swaps in each freshly audited row), so both always match.
    Expects $product, $channel and $auditConfig; optionally $isSelected,
    $showListingIssues and $cdqByAsin (VC only).

    data-search, data-filters and data-sort-value drive the search box,
    filter buttons and column sort in resources/js/tools/listings.js. They
    are computed here because several cells ("3 hours ago", "2 mismatched",
    a symbol) don't sort or filter usefully as displayed.
--}}
@php
    use App\Services\AmazonListings\AttributeEngine;
    use App\Services\AmazonListings\ListingChannel;

    $isVendor = $channel === ListingChannel::Vendor;
    $isSelected = $isSelected ?? false;
    $routeName = $isVendor ? 'tools.vc-listings.index' : 'tools.sc-listings.index';
    $routeParam = $isVendor ? ['asin' => $product->asin] : ['sku' => $product->sku];
    $rowUrl = route($routeName, $routeParam).'#row-'.$product->id;
    $audit = $product->latestListingAudit;
    $audited = $audit && $audit->audited_attributes ? $audit->audited_attributes : [];
    $cdq = ($cdqByAsin ?? [])[$product->asin] ?? null;

    $rowFilters = [];
    if ($audit && $audit->contribution_mismatch_count > 0) {
        $rowFilters[] = 'mismatch';
    }
    if (($audit->aplus_status ?? null) === 'none') {
        $rowFilters[] = 'noaplus';
    }
    if ($isVendor && $audit && ! empty($audit->listing_issues)) {
        $rowFilters[] = 'issues';
    }
    if ($isVendor && $cdq && in_array($cdq['grade'] ?? null, ['C', 'D'], true)) {
        $rowFilters[] = 'cdq-attention';
    }
    $searchBlob = mb_strtolower($product->title.' '.$product->asin.' '.$product->sku);

    $aplusRank = match ($audit->aplus_status ?? null) {
        'full' => 3, 'brand_only' => 2, 'none' => 1, default => 0,
    };
@endphp
<tr id="row-{{ $product->id }}" @class(['is-selected' => $isSelected])
    data-search="{{ $searchBlob }}" data-filters="{{ implode(' ', $rowFilters) }}"
    @if ($isVendor) data-asin="{{ $product->asin }}" @else data-sku="{{ $product->sku }}" @endif>
    <td class="product-cell">
        <a href="{{ $rowUrl }}" class="listing-row-link product-title" title="{{ $product->title }}">{{ $product->title }}</a>
        <div class="mono muted text-xs">{{ $product->asin }} · {{ $product->sku }}</div>
    </td>
    @foreach ($auditConfig as $slotKey)
        @php $entry = $audited[$slotKey] ?? null; @endphp
        <td class="text-center" @if ($entry && $entry['kind'] !== 'count') data-sort-value="{{ $entry['value'] ? 1 : 0 }}" @endif>
            @if (! $entry)
                <span class="muted">—</span>
            @elseif ($entry['kind'] === 'count')
                {{ $entry['value'] }}
            @elseif ($entry['value'])
                <span title="{{ __('Present') }}">✅</span><span class="sr-only">{{ __('Present') }}</span>
            @else
                <span title="{{ __('Missing') }}">❌</span><span class="sr-only">{{ __('Missing') }}</span>
            @endif
        </td>
    @endforeach
    @if ($channel->includeVendorDetails())
        <td class="mono">{{ $audit->brand_code ?? '—' }}</td>
        <td class="mono">{{ $audit->manufacturer_code ?? '—' }}</td>
    @endif
    <td data-sort-value="{{ $aplusRank }}">@include('tools.listings._aplus-badge', ['status' => $audit->aplus_status ?? null])</td>
    <td class="num">{{ $audit->image_count ?? '—' }}</td>
    <td @if ($audit && $audit->contribution_mismatch_count !== null) data-sort-value="{{ $audit->contribution_mismatch_count }}" @endif>
        @if (! $audit || $audit->contribution_mismatch_count === null)
            <span class="muted">—</span>
        @elseif ($audit->contribution_mismatch_count === 0)
            <x-status-badge tone="good">{{ __('Matches') }}</x-status-badge>
        @else
            <x-status-badge tone="bad">{{ __(':n mismatched', ['n' => $audit->contribution_mismatch_count]) }}</x-status-badge>
        @endif
    </td>
    @if ($channel->includeVendorDetails())
        <td title="{{ $audit?->replenishment_category ? AttributeEngine::humanizeKey($audit->replenishment_category) : '' }}">
            {{ $audit?->replenishment_category ? AttributeEngine::replenishmentAbbreviation($audit->replenishment_category) : '—' }}
        </td>
        <td data-sort-value="{{ $cdq['grade'] ?? 'Z' }}" title="{{ $cdq ? __('Score :s/100', ['s' => number_format($cdq['total_score'] ?? 0, 1)]) : '' }}">
            @if ($cdq)
                @php
                    $tone = match ($cdq['grade']) { 'A', 'B' => 'good', 'C' => 'warn', 'D' => 'bad', default => 'mute' };
                @endphp
                <a href="{{ route('tools.cdq-report.show', $product->asin) }}" class="grade-badge tone tone-{{ $tone }}"><span>{{ $cdq['grade'] }}</span></a>
            @else
                <span class="muted">—</span>
            @endif
        </td>
    @endif
    @if ($showListingIssues ?? false)
        {{-- The tooltip is on the whole cell, not just the symbol, so it's easy to hover. --}}
        @if ($audit?->listing_issues === null)
            <td data-sort-value="-1"><span class="muted">—</span></td>
        @elseif (empty($audit->listing_issues))
            <td data-sort-value="0" class="has-tooltip" title="{{ __('No open Listings Items issues.') }}">🟢<span class="sr-only">{{ __('None open') }}</span></td>
        @else
            <td data-sort-value="1" class="has-tooltip" title="{{ AttributeEngine::summarizeListingIssues($audit->listing_issues) }}">🔴<span class="sr-only">{{ __(':n open', ['n' => count($audit->listing_issues)]) }}</span></td>
        @endif
    @endif
    <td class="whitespace-nowrap text-xs muted" @if ($audit?->checked_at) data-sort-value="{{ $audit->checked_at->timestamp }}" title="{{ $audit->checked_at->format('Y-m-d H:i T') }}" @endif>{{ $audit?->checked_at?->diffForHumans() ?? '—' }}</td>
    <td>
        <a href="{{ $rowUrl }}" class="listing-row-link icon-btn icon-btn-sm" aria-expanded="{{ $isSelected ? 'true' : 'false' }}" aria-label="{{ __('Edit this listing') }}">
            <x-icon name="chevron-down" size="sm" />
        </a>
    </td>
</tr>
