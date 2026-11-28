{{--
    The listing editor, shared by both channels: rendered inside the page
    when it loads with a listing selected, or alone (?ajax=1) when a row is
    expanded. Every attribute Amazon returns is editable; the form posts each
    value with its original (orig[...]) so the controller sends only what
    changed. Expects the controller's loadEditorData() plus $submitRoute.
--}}
@php
    use App\Services\AmazonListings\AttributeEngine;
    use App\Services\AmazonListings\NutritionFormBuilder;
    use App\Support\ExternalWrites;

    $descAttr = $channel->descriptionAttributeKey();
    $extIdAttr = $channel->externalIdAttributeKey();
    $topSection = $channel->topSectionAttributes();
    $multiEntry = $channel->multiEntryAttributes();

    $bulletItems = $attributes['bullet_point'] ?? [];
    $bulletItems = array_pad($bulletItems, 5, []);

    $schemaProps = $schemaProps ?? [];
    $mismatchedAttrs = $mismatchedAttrs ?? [];
    $browseNodeNames = $browseNodeNames ?? [];
    // A field whose attribute drifted from our last accepted submission (the
    // last audit's contribution check) gets a red outline and a short note,
    // so the exact value to quote in an Amazon support ticket is easy to find.
    $isMismatched = fn (string $attrKey): bool => in_array($attrKey, $mismatchedAttrs, true);
    $fieldId = 'le-'.$product->id;
@endphp

<form method="post" action="{{ $submitRoute }}" class="listing-editor form-stack" id="listing-editor-form">
    @csrf
    <input type="hidden" name="asin" value="{{ $product->asin }}">
    <input type="hidden" name="sku" value="{{ $product->sku }}">
    <input type="hidden" name="product_type" value="{{ $productType }}">

    <div class="grid gap-4 md:grid-cols-2">
        <div class="field">
            <label for="{{ $fieldId }}-sku">{{ __('Vendor/Seller SKU') }}</label>
            <input type="text" class="input" id="{{ $fieldId }}-sku" value="{{ $product->sku }}" disabled>
        </div>
        <div class="field">
            <label for="{{ $fieldId }}-extid">{{ __('External Product ID') }}</label>
            <input type="text" class="input" id="{{ $fieldId }}-extid" value="{{ implode(', ', array_map(fn ($i) => $i['value'] ?? '', $attributes[$extIdAttr] ?? [])) ?: '—' }}" disabled>
        </div>
    </div>

    @php $itemNameItems = $attributes['item_name'] ?? []; @endphp
    <div @class(['field', 'listing-field-mismatch' => $isMismatched('item_name')])>
        <label for="{{ $fieldId }}-title">{{ __('Title') }}</label>
        @includeWhen($isMismatched('item_name'), 'tools.listings._mismatch-warning')
        {{-- No maxlength: real titles here already pass the commonly cited 75/125
             characters, and a cap stricter than Amazon's would block valid edits.
             Amazon's VALIDATION_PREVIEW is the gatekeeper. --}}
        <input type="text" class="input" id="{{ $fieldId }}-title" name="attr[item_name][0][value]" value="{{ $itemNameItems[0]['value'] ?? '' }}">
        <input type="hidden" name="orig[item_name]" value='{{ json_encode($itemNameItems) }}'>
    </div>

    @php $titleDiffItems = $attributes['title_differentiation'] ?? []; @endphp
    <div @class(['field', 'listing-field-mismatch' => $isMismatched('title_differentiation')])>
        <label for="{{ $fieldId }}-excerpt">{{ __('Product Excerpt / Item Highlight') }}</label>
        @includeWhen($isMismatched('title_differentiation'), 'tools.listings._mismatch-warning')
        <input type="text" class="input" id="{{ $fieldId }}-excerpt" maxlength="125" name="attr[title_differentiation][0][value]" value="{{ $titleDiffItems[0]['value'] ?? '' }}" aria-describedby="{{ $fieldId }}-excerpt-hint">
        <p class="field-hint" id="{{ $fieldId }}-excerpt-hint">{{ __('Only shows to shoppers if the title itself is under 75 characters.') }}</p>
        <input type="hidden" name="orig[title_differentiation]" value='{{ json_encode($titleDiffItems) }}'>
    </div>

    <fieldset @class(['field', 'listing-field-mismatch' => $isMismatched('bullet_point')])>
        <legend class="label">{{ __('Bullet Points') }}</legend>
        @includeWhen($isMismatched('bullet_point'), 'tools.listings._mismatch-warning')
        @foreach ($bulletItems as $i => $bullet)
            <div class="listing-bullet">
                <span class="muted text-sm" aria-hidden="true">{{ $i + 1 }}.</span>
                <input type="text" class="input" maxlength="100" name="attr[bullet_point][{{ $i }}][value]" value="{{ $bullet['value'] ?? '' }}" aria-label="{{ __('Bullet :n', ['n' => $i + 1]) }}">
            </div>
        @endforeach
        <input type="hidden" name="orig[bullet_point]" value='{{ json_encode(array_values($attributes['bullet_point'] ?? [])) }}'>
    </fieldset>

    @php $descItems = $attributes[$descAttr] ?? []; @endphp
    <div @class(['field', 'listing-field-mismatch' => $isMismatched($descAttr)])>
        <label for="{{ $fieldId }}-desc">{{ __('Description') }}</label>
        @includeWhen($isMismatched($descAttr), 'tools.listings._mismatch-warning')
        <textarea class="input textarea" rows="4" id="{{ $fieldId }}-desc" name="attr[{{ $descAttr }}][0][value]">{{ $descItems[0]['value'] ?? '' }}</textarea>
        <input type="hidden" name="orig[{{ $descAttr }}]" value='{{ json_encode($descItems) }}'>
    </div>

    @if (! empty($nutritionSchemaProps))
        @php $ni = $attributes['nutritional_info'][0] ?? []; @endphp
        <fieldset @class(['listing-group', 'listing-field-mismatch' => $isMismatched('nutritional_info')])>
            <legend class="card-title">{{ __('Nutrition Facts') }}</legend>
            @includeWhen($isMismatched('nutritional_info'), 'tools.listings._mismatch-warning')
            <input type="hidden" name="orig_nutritional_info" value='{{ json_encode($attributes['nutritional_info'] ?? []) }}'>
            <input type="hidden" name="nutrition_schema_props" value='{{ json_encode(array_values(array_intersect(array_keys(NutritionFormBuilder::GROUPS), array_keys($nutritionSchemaProps)))) }}'>

            <div class="grid gap-3 md:grid-cols-2">
                <div class="field">
                    <label for="{{ $fieldId }}-sq">{{ __('Serving Quantity') }}</label>
                    <input type="number" step="any" class="input" id="{{ $fieldId }}-sq" name="nutrition[serving_quantity]" value="{{ $ni['serving_quantity'] ?? '' }}">
                </div>
                <div class="field">
                    <label for="{{ $fieldId }}-su">{{ __('Serving Unit') }}</label>
                    <input type="text" class="input" id="{{ $fieldId }}-su" name="nutrition[serving_unit]" value="{{ $ni['serving_unit'] ?? '' }}">
                </div>
                <div class="field md:col-span-2">
                    <label for="{{ $fieldId }}-sd">{{ __('Serving Description') }}</label>
                    <input type="text" class="input" id="{{ $fieldId }}-sd" name="nutrition[serving_description][value]" value="{{ $ni['serving_description'][0]['value'] ?? '' }}">
                </div>
            </div>

            @foreach (NutritionFormBuilder::GROUPS as $groupKey => $groupLabel)
                @php $groupProps = $nutritionSchemaProps[$groupKey]['items']['properties'] ?? null; @endphp
                @if ($groupProps)
                    @php $existingGroup = $ni[$groupKey][0] ?? []; @endphp
                    <div class="flex flex-col gap-2">
                        <div class="label">{{ $groupLabel }}</div>
                        <div class="grid gap-3 md:grid-cols-3">
                            @foreach ($groupProps as $subKey => $propSchema)
                                @php
                                    $kind = AttributeEngine::schemaFieldKind($propSchema);
                                    $val = $existingGroup[$subKey] ?? ($groupKey === 'energy' && $subKey === 'unit' ? 'kilocalories' : null);
                                    $name = "nutrition[{$groupKey}][{$subKey}]";
                                    $inputId = $fieldId.'-n-'.$groupKey.'-'.$subKey;
                                @endphp
                                <div class="field">
                                    <label for="{{ $inputId }}" class="text-sm">{{ $propSchema['title'] ?? AttributeEngine::humanizeKey($subKey) }}</label>
                                    @if ($kind === 'enum')
                                        <select class="select" id="{{ $inputId }}" name="{{ $name }}">
                                            <option value="">—</option>
                                            @foreach (AttributeEngine::schemaFieldEnumOptions($propSchema) as $code => $label)
                                                <option value="{{ $code }}" {{ (string) $val === (string) $code ? 'selected' : '' }}>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input type="{{ $kind === 'number' ? 'number' : 'text' }}" step="any" class="input" id="{{ $inputId }}" name="{{ $name }}" value="{{ $val }}">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            @php $vmRows = array_pad($ni['vitamins_and_minerals'] ?? [], 1, []); @endphp
            <div class="flex flex-col gap-2">
                <div class="label">{{ __('Vitamins & Minerals') }}</div>
                @foreach ($vmRows as $i => $row)
                    <div class="grid gap-3 md:grid-cols-3">
                        <input type="text" class="input" placeholder="{{ __('Nutrient') }}" aria-label="{{ __('Nutrient') }}" name="nutrition[vitamins_and_minerals][{{ $i }}][nutrient]" value="{{ $row['nutrient'] ?? '' }}">
                        <input type="number" step="any" class="input" placeholder="{{ __('Value') }}" aria-label="{{ __('Value') }}" name="nutrition[vitamins_and_minerals][{{ $i }}][value]" value="{{ $row['value'] ?? '' }}">
                        <input type="text" class="input" placeholder="{{ __('Unit') }}" aria-label="{{ __('Unit') }}" name="nutrition[vitamins_and_minerals][{{ $i }}][unit]" value="{{ $row['unit'] ?? '' }}">
                    </div>
                @endforeach
            </div>
        </fieldset>
    @endif

    @foreach ($attributes as $attrKey => $items)
        @continue(in_array($attrKey, $topSection, true))
        @php
            $isMulti = in_array($attrKey, $multiEntry, true);
            $rows = $items;
            if ($isMulti || empty($rows)) $rows[] = [];
            // The schema for one item of this attribute, to find each leaf's
            // own schema (schemaLeafAt). Null when the schema has nothing for
            // this key, so the widget falls back to the value's own type.
            $itemSchema = $schemaProps[$attrKey]['items'] ?? null;
        @endphp
        <fieldset @class(['listing-group', 'listing-field-mismatch' => $isMismatched($attrKey)])>
            <legend class="label">{{ AttributeEngine::humanizeKey($attrKey) }}</legend>
            @includeWhen($isMismatched($attrKey), 'tools.listings._mismatch-warning')
            @foreach ($rows as $idx => $item)
                @php $leaves = AttributeEngine::leafPaths($item ?: (($items[0] ?? null) ? array_fill_keys(array_keys($items[0]), null) : ['value' => null])); @endphp
                <div class="listing-item">
                    @foreach ($leaves as [$path, $tmplVal])
                        @php $label = AttributeEngine::humanizeLeafPath($path); @endphp
                        @continue($label === null)
                        @php
                            $val = AttributeEngine::getPath($item, $path);
                            $name = 'attr['.$attrKey.']['.$idx.']['.implode('.', $path).']';
                            $inputId = $fieldId.'-'.$attrKey.'-'.$idx.'-'.implode('-', $path);
                            // Amazon's own schema wins when it limits this leaf to a fixed set
                            // (a weight unit, say), so nobody types a value Amazon will reject.
                            // Only enum overrides the kind inferred from the value.
                            $leafSchema = $itemSchema ? AttributeEngine::schemaLeafAt($itemSchema, $path) : null;
                            $schemaKind = $leafSchema ? AttributeEngine::schemaFieldKind($leafSchema) : null;
                            $kind = $schemaKind === 'enum' ? 'enum' : AttributeEngine::widgetKind($val);
                        @endphp
                        <div class="field">
                            <label for="{{ $inputId }}" class="text-sm muted">{{ $label }}</label>
                            @if ($kind === 'enum')
                                <select class="select" id="{{ $inputId }}" name="{{ $name }}">
                                    <option value="">—</option>
                                    @foreach (AttributeEngine::schemaFieldEnumOptions($leafSchema) as $code => $optionLabel)
                                        <option value="{{ $code }}" {{ (string) $val === (string) $code ? 'selected' : '' }}>{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif ($kind === 'checkbox')
                                <input type="hidden" name="{{ $name }}" value="0">
                                <label class="check"><input type="checkbox" id="{{ $inputId }}" name="{{ $name }}" value="1" {{ $val ? 'checked' : '' }}> {{ __('Yes') }}</label>
                            @elseif ($kind === 'number')
                                <input type="number" step="any" class="input" id="{{ $inputId }}" name="{{ $name }}" value="{{ $val }}">
                            @elseif ($kind === 'textarea')
                                <textarea class="input textarea" id="{{ $inputId }}" name="{{ $name }}">{{ $val }}</textarea>
                            @else
                                <input type="text" class="input" id="{{ $inputId }}" name="{{ $name }}" value="{{ $val }}">
                            @endif
                            @if ($attrKey === 'recommended_browse_nodes' && $val && isset($browseNodeNames[(string) $val]))
                                <p class="field-hint">{{ $browseNodeNames[(string) $val] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
            <input type="hidden" name="orig[{{ $attrKey }}]" value='{{ json_encode(array_values($items)) }}'>
        </fieldset>
    @endforeach

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">{{ ExternalWrites::allowed('listings') ? __('Validate & submit') : __('Validate only') }}</button>
        @unless (ExternalWrites::allowed('listings'))
            <p class="muted text-sm">{{ __("Submissions are off in this app until cutover: Amazon's validation preview runs, and nothing is sent.") }}</p>
        @endunless
    </div>
</form>

@php
    // Both come from the latest audit (the same row the table's LI column
    // reads), not a new call. Null means never checked; [] means Amazon
    // reported none.
    $listingIssues = $product->latestListingAudit?->listing_issues;
    // Amazon's docs never confirm that the restrictions call works for vendor
    // accounts; it's asked anyway, to see what comes back.
    $listingRestrictions = $product->latestListingAudit?->listing_restrictions;
@endphp
<div class="flex flex-col gap-3 mt-6">
    <details class="card scope-card" id="listing-issues" @if (! empty($listingIssues)) open @endif>
        <summary class="card-body font-semibold"><span class="inline-flex flex-wrap items-center gap-2">
            {{ __('Listing Issues') }}
            @if ($listingIssues === null)
                <x-status-badge tone="mute">{{ __('Never audited') }}</x-status-badge>
            @elseif (empty($listingIssues))
                <x-status-badge tone="good">{{ __('None open') }}</x-status-badge>
            @else
                <x-status-badge tone="bad">{{ trans_choice(':count open|:count open', count($listingIssues)) }}</x-status-badge>
            @endif
        </span></summary>
        <div class="card-body pt-0">
            @if ($listingIssues === null)
                <p class="muted text-sm">{{ __('Not audited yet — run Audit Listings from the picker page to check for open issues.') }}</p>
            @elseif (empty($listingIssues))
                <p class="text-sm">{{ __('No open Listings Items issues as of the last audit.') }}</p>
            @else
                <p class="muted text-sm">{{ __("Amazon's own explanation, when it has one — not every suppression has a matching issue here; this is whatever Amazon has actually reported for this listing.") }}</p>
                <ul class="listing-issue-list">
                    @foreach ($listingIssues as $issue)
                        @php
                            $severity = $issue['severity'] ?? '?';
                            $sevTone = match ($severity) { 'ERROR' => 'bad', 'WARNING' => 'warn', default => 'mute' };
                            $actions = array_filter(array_map(fn ($a) => $a['action'] ?? null, $issue['enforcements']['actions'] ?? []));
                        @endphp
                        <li>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <x-status-badge :tone="$sevTone">{{ $severity }}</x-status-badge>
                                @if ($actions)
                                    <span class="text-sm font-semibold tone-text-bad">{{ implode(', ', $actions) }}</span>
                                @endif
                            </div>
                            <p class="text-sm">{{ $issue['message'] ?? '' }}</p>
                            @if (! empty($issue['attributeNames']))
                                <p class="muted text-sm">{{ __('Attributes:') }} {{ implode(', ', $issue['attributeNames']) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </details>

    <details class="card scope-card" id="listing-restrictions" @if (! empty($listingRestrictions)) open @endif>
        <summary class="card-body font-semibold"><span class="inline-flex flex-wrap items-center gap-2">
            {{ __('Listing Restrictions') }}
            @if ($listingRestrictions === null)
                <x-status-badge tone="mute">{{ __('Unavailable') }}</x-status-badge>
            @elseif (empty($listingRestrictions))
                <x-status-badge tone="good">{{ __('None') }}</x-status-badge>
            @else
                <x-status-badge tone="warn">{{ count($listingRestrictions) }}</x-status-badge>
            @endif
        </span></summary>
        <div class="card-body pt-0">
            @if ($listingRestrictions === null)
                <p class="muted text-sm">{{ __("Amazon's Listings Restrictions API either hasn't been checked yet or didn't return usable data for this vendor account — its docs never confirm vendor support, unlike the issues above. Not necessarily a sign of a problem.") }}</p>
            @elseif (empty($listingRestrictions))
                <p class="text-sm">{{ __('Amazon reported no restrictions for this ASIN as of the last audit.') }}</p>
            @else
                <ul class="listing-issue-list">
                    @foreach ($listingRestrictions as $restriction)
                        <li>
                            @if (! empty($restriction['conditionType']))
                                <x-status-badge tone="mute" :icon="false">{{ $restriction['conditionType'] }}</x-status-badge>
                            @endif
                            @foreach ($restriction['reasons'] ?? [] as $reason)
                                <p class="text-sm">
                                    @if (! empty($reason['reasonCode']))
                                        <span class="font-semibold">{{ $reason['reasonCode'] }}:</span>
                                    @endif
                                    {{ $reason['message'] ?? '' }}
                                </p>
                                @foreach ($reason['links'] ?? [] as $link)
                                    <a href="{{ $link['resource'] ?? '#' }}" target="_blank" rel="noopener" class="text-sm">{{ $link['title'] ?? __('Path forward') }} →</a>
                                @endforeach
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </details>
</div>

<div class="flex flex-col gap-2 mt-6">
    <h3 class="card-title">{{ __('Edit history') }}</h3>
    <p class="muted text-sm">{{ __("Every submission sent to Amazon, with Amazon's answer. Only accepted ones count toward Our Contribution.") }}</p>
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">{{ __('Edit history for this listing') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('When') }}</th>
                    <th scope="col">{{ __('Attribute') }}</th>
                    <th scope="col">{{ __('By') }}</th>
                    <th scope="col">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($edits as $edit)
                    <tr>
                        <td class="whitespace-nowrap">{{ $edit->created_at->format('Y-m-d H:i') }}</td>
                        <td class="mono">{{ $edit->attribute }}</td>
                        <td class="muted">{{ $edit->user->name ?? __('— (imported history)') }}</td>
                        <td><x-status-badge :tone="$edit->status === 'ACCEPTED' ? 'good' : 'bad'">{{ $edit->status }}</x-status-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">{{ __('No edits submitted yet for this listing.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
