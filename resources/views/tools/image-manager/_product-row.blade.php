{{--
    One row of the products table, shared by the page and the audit batch
    response (which swaps in each freshly audited row). Expects $product and
    optionally $isSelected.
--}}
@php
    $isSelected = $isSelected ?? false;
    $rowUrl = route('tools.vc-image-manager.index', ['asin' => $product->asin]).'#row-'.$product->id;
    $audit = $product->latestImageAudit;
@endphp
<tr id="row-{{ $product->id }}" @class(['is-selected' => $isSelected]) data-asin="{{ $product->asin }}"
    data-search="{{ mb_strtolower($product->title.' '.$product->asin.' '.$product->sku) }}">
    <td class="product-cell">
        <a href="{{ $rowUrl }}" class="image-row-link product-title" title="{{ $product->title }}">{{ $product->title }}</a>
        <div class="mono muted text-xs">{{ $product->asin }} · {{ $product->sku }}</div>
    </td>
    <td data-sort-value="{{ $audit && ! $audit->fetch_error ? $audit->unattributed_count : -1 }}">
        @if (! $audit)
            <span class="muted">—</span>
        @elseif ($audit->fetch_error)
            <span class="has-tooltip" title="{{ $audit->fetch_error }}"><x-status-badge tone="mute">{{ __('fetch failed') }}</x-status-badge></span>
        @elseif ($audit->unattributed_count === 0)
            <x-status-badge tone="good">{{ __('None') }}</x-status-badge>
        @else
            <x-status-badge tone="bad">{{ __(':n not ours', ['n' => $audit->unattributed_count]) }}</x-status-badge>
        @endif
    </td>
    <td data-sort-value="{{ $audit && ! $audit->fetch_error ? $audit->not_live_count : -1 }}">
        @if (! $audit || $audit->fetch_error)
            <span class="muted">—</span>
        @elseif ($audit->not_live_count === 0)
            <x-status-badge tone="good">{{ __('None') }}</x-status-badge>
        @else
            <x-status-badge tone="warn">{{ __(':n not live', ['n' => $audit->not_live_count]) }}</x-status-badge>
        @endif
    </td>
    <td class="whitespace-nowrap text-xs muted" @if ($audit?->checked_at) data-sort-value="{{ $audit->checked_at->timestamp }}" title="{{ $audit->checked_at->format('Y-m-d H:i T') }}" @endif>{{ $audit?->checked_at?->diffForHumans() ?? '—' }}</td>
    <td>
        <a href="{{ $rowUrl }}" class="image-row-link icon-btn icon-btn-sm" aria-expanded="{{ $isSelected ? 'true' : 'false' }}" aria-label="{{ __('Show this product\'s images') }}">
            <x-icon name="chevron-down" size="sm" />
        </a>
    </td>
</tr>
