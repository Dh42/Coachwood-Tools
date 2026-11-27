<x-layouts.app :title="$audit->sku" breadcrumb="Walmart · Listing Auditor">
    <x-slot:subtitle>{{ $product_name ?? __('(no product name on file)') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('tools.walmart-us-listing-auditor.index') }}" class="btn">{{ __('All SKUs') }}</a>
    </x-slot:actions>

    <x-stat-row class="mb-4">
        @foreach ([
            'Listing Quality' => $audit->listing_quality,
            'Content' => $audit->content_score,
            'Offer' => $audit->offer_score,
            'Rating & Reviews' => $audit->rating_review_score,
            'Price' => $audit->price_score,
            'Shipping' => $audit->shipping_score,
            'Transactibility' => $audit->transactibility_score,
        ] as $label => $value)
            <x-stat-tile :label="__($label)" :value="$value !== null ? number_format((float) $value, 1) : null" />
        @endforeach
    </x-stat-row>

    <x-card :title="__('Issues on this audit')" class="mb-4">
        @if ($issues->isEmpty())
            <x-empty-state :title="__('No issues')" icon="check">{{ __("Walmart reported no issues for this SKU's latest audit.") }}</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Code') }}</th>
                            <th scope="col">{{ __('Severity') }}</th>
                            <th scope="col">{{ __('Field') }}</th>
                            <th scope="col">{{ __('Message') }}</th>
                            <th scope="col">{{ __('Recommended action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($issues as $issue)
                            <tr>
                                <td><code>{{ $issue->code }}</code></td>
                                <td>
                                    @if ($issue->severity)
                                        <x-status-badge tone="mute" :icon="false">{{ $issue->severity }}</x-status-badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $issue->field ?? '—' }}</td>
                                <td class="wrap-cell muted">{{ $issue->message ?? '—' }}</td>
                                <td class="wrap-cell muted">{{ $issue->recommended_action ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <p class="faint text-xs">
        {{ __('Audit run :run · as of :asof', ['run' => $audit->run_at->format('Y-m-d H:i'), 'asof' => $audit->as_of_date]) }}
        @if ($snapshot)
            {{ __('· snapshot captured :date · content hash :hash', ['date' => $snapshot->captured_at->format('Y-m-d H:i'), 'hash' => substr($snapshot->content_hash, 0, 12)]) }}
        @endif
    </p>
</x-layouts.app>
