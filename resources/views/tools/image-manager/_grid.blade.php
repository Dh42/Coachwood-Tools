{{--
    The 9 image slots for one product (Main and Other 1 to 8): what's live on
    Amazon beside what we last submitted, with a status, and the forms to
    upload, replace or remove. Expects $product, $liveByVariant (Catalog Items
    images, one per variant), $slotStatuses (ImageAuditService::
    computeSlotStatuses), $imageAttributeMap, $uploadRoute and $removeRoute.
--}}
@php
    use App\Support\ExternalWrites;

    $labels = ['MAIN' => __('Main')] + collect(range(1, 8))->mapWithKeys(fn ($n) => ['PT0'.$n => __('Other :n', ['n' => $n])])->all();
    $badges = [
        'live' => ['tone' => 'good', 'label' => __('Live'), 'title' => __("Our last accepted submission matches Amazon's live listing data for this slot.")],
        'not_live' => ['tone' => 'warn', 'label' => __('Not live'), 'title' => __("We have an accepted submission for this slot, but it doesn't currently match Amazon's live listing data. That's Vendor Central's own wording when your submitted image isn't the one showing.")],
        'not_ours' => ['tone' => 'bad', 'label' => __('Not ours'), 'title' => __('Live, but never submitted by us, or changed since we last submitted it.')],
    ];
@endphp
<div id="image-grid" class="flex flex-col gap-4" data-asin="{{ $product->asin }}">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="card-title">{{ $product->title }} <span class="mono muted text-xs">{{ $product->asin }} · {{ $product->sku }}</span></h3>
        @unless (ExternalWrites::allowed())
            <p class="muted text-sm">{{ __('Submissions are off until cutover: Amazon validates each change, and nothing is sent.') }}</p>
        @endunless
    </div>
    <div class="image-slots">
        @foreach ($imageAttributeMap as $variant => $attrKey)
            @php
                $live = $liveByVariant->get($variant);
                $slot = $slotStatuses[$variant] ?? ['status' => 'empty', 'ours_url' => null];
                $badge = $badges[$slot['status']] ?? null;
                $fileId = 'image-file-'.$product->id.'-'.$variant;
            @endphp
            <section @class(['image-slot', 'image-slot-'.$slot['status']]) aria-labelledby="slot-{{ $variant }}">
                <div class="flex items-center justify-between gap-2">
                    <h4 class="font-semibold text-sm" id="slot-{{ $variant }}">{{ $labels[$variant] }}</h4>
                    @if ($badge)
                        <span class="has-tooltip" title="{{ $badge['title'] }}"><x-status-badge :tone="$badge['tone']">{{ $badge['label'] }}</x-status-badge></span>
                    @endif
                </div>
                <div class="image-pair">
                    <figure>
                        <div class="image-frame">
                            @if ($live)
                                <img src="{{ $live['link'] }}" alt="{{ __(':slot, live on Amazon', ['slot' => $labels[$variant]]) }}" loading="lazy">
                            @else
                                <span class="muted text-xs">{{ __('Empty') }}</span>
                            @endif
                        </div>
                        <figcaption class="muted text-xs">{{ __('Live now') }}</figcaption>
                    </figure>
                    <figure>
                        <div class="image-frame">
                            @if ($slot['ours_url'])
                                <img src="{{ $slot['ours_url'] }}" alt="{{ __(':slot, our last submission', ['slot' => $labels[$variant]]) }}" loading="lazy">
                            @else
                                <span class="muted text-xs">{{ __('None submitted') }}</span>
                            @endif
                        </div>
                        <figcaption class="muted text-xs">{{ __('Our submission') }}</figcaption>
                    </figure>
                </div>
                <form method="post" action="{{ $uploadRoute }}" enctype="multipart/form-data" class="image-upload-form flex flex-col gap-2">
                    @csrf
                    <input type="hidden" name="asin" value="{{ $product->asin }}">
                    <input type="hidden" name="variant" value="{{ $variant }}">
                    <label class="sr-only" for="{{ $fileId }}">{{ __('Image file for :slot', ['slot' => $labels[$variant]]) }}</label>
                    <input type="file" id="{{ $fileId }}" name="image" class="input image-file" accept="image/jpeg,image/png,image/gif,image/tiff" required>
                    <button type="submit" class="btn btn-sm btn-primary">{{ $live ? __('Replace') : __('Upload') }}</button>
                </form>
                @if ($live)
                    <form method="post" action="{{ $removeRoute }}" class="image-remove-form" data-slot-label="{{ $labels[$variant] }}">
                        @csrf
                        <input type="hidden" name="asin" value="{{ $product->asin }}">
                        <input type="hidden" name="variant" value="{{ $variant }}">
                        <button type="submit" class="btn btn-sm btn-danger w-full">{{ __('Remove') }}</button>
                    </form>
                @endif
            </section>
        @endforeach
    </div>
</div>
