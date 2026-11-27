{{-- US Product Reviews, ported from the old app onto the new design. Summary and detail are one route (?asin=...). --}}
@php
    $tourSteps = [
        ['element' => '#feedback-empty-state', 'intro' => __('No data has been pulled in yet — this tool has nothing to show until feedback:collect has run at least once.')],
        ['element' => '#feedback-scope', 'intro' => __("Worth reading before the numbers below: US marketplace only (Amazon.ca isn't supported by this API at all); both our VC and SC catalogs are queried but the underlying data is identical either way — it's brand-level, not account-scoped; this is Amazon's own topic classification, not raw review text or IDs; and the reviews figure next to each topic is arithmetic on Amazon's own occurrence %, not a number Amazon reports directly.")],
        ['element' => '#feedback-summary-table', 'intro' => __('One row per ASIN — catalog badge (VC only / SC only / Both, meaning which of our systems currently lists it, not a data difference), pull status, topic counts, and the single top positive/negative topic. Click an ASIN to see its full topic breakdown.')],
        ['element' => '#feedback-detail', 'intro' => __('Every topic Amazon classified for this ASIN, each marked positive or negative. Mentions and occurrence % are compared against the category benchmark Amazon itself provides; star impact is the same comparison for rating effect. Where present: a 6-month occurrence trend, up to 3 verbatim customer snippets per topic, and subtopics with their own mention counts and snippets.')],
    ];
@endphp
<x-layouts.app title="US Product Reviews" :breadcrumb="$selectedAsin === '' ? 'Amazon · Listings' : 'Amazon · US Product Reviews'" :heading="$selectedAsin === '' ? null : $selectedAsin">
    <x-slot:subtitle>{{ __("Amazon's own review-topic classification — every BioSteel ASIN across both catalogs, US marketplace. Not raw reviews; Amazon's topic/subtopic classification plus selected verbatim snippets.") }}</x-slot:subtitle>
    <x-slot:actions>
        @if ($selectedAsin !== '')
            <a href="{{ route('tools.us-product-reviews.index') }}" class="btn">{{ __('All ASINs') }}</a>
        @endif
        <x-tour-button id="feedback-tour-btn" :steps="$tourSteps" />
    </x-slot:actions>

    @if (! $hasAnyData)
        <x-card id="feedback-empty-state">
            <x-empty-state :title="__('No feedback data yet')" icon="star">
                {{ __('No feedback data yet. Use "Run Now" on the') }} <code>feedback:collect</code> {{ __('task on the Scheduler admin page to populate this.') }}
            </x-empty-state>
        </x-card>
    @else
        <details class="card mb-4 scope-card" id="feedback-scope" @if ($selectedAsin === '') open @endif>
            <summary class="card-body font-semibold">{{ __('Scope — read this first') }}</summary>
            <dl class="scope-list">
                <dt>{{ __('US marketplace only') }}</dt>
                <dd>{{ __('Amazon.ca isn\'t supported by this API at all (confirmed live — it\'s not in the API\'s own marketplace enum), so this only covers our US-marketplace review data — from either sales channel.') }}</dd>
                <dt>{{ __('Both catalogs, one dataset') }}</dt>
                <dd>{{ __('Every ASIN from our Vendor Central (.ca) catalog and Seller Central (US) catalog is queried. The data itself is identical regardless of which account asks — this is brand-level Amazon data. The badge below just shows which of our own systems currently lists each ASIN.') }}</dd>
                <dt>{{ __('Not raw reviews') }}</dt>
                <dd>{{ __('Amazon provides its own topic/subtopic classification, occurrence %, mention counts, star-rating impact, and up to 3 verbatim snippets per topic — no review IDs, dates, reviewers, or full text.') }}</dd>
                <dt>{{ __('"~N reviews" is derived, not reported') }}</dt>
                <dd>{{ __('Amazon documents occurrence % as "the percentage of reviews that mention the topic" — so mentions ÷ occurrence % gives an approximate count of reviews behind that topic. Arithmetic on Amazon\'s own numbers, not a field Amazon reports directly.') }}</dd>
                <dt>{{ __('6-month trend') }}</dt>
                <dd>{{ __('A separate API call from the topic snapshot — occurrence % only, month by month, no mention counts at that grain. Collected weekly via its own scheduled task, independent of the main topics collector.') }}</dd>
            </dl>
        </details>

        @if ($selectedAsin === '')
            <div id="feedback-summary-table">
                @include('tools.feedback._summary')
            </div>
        @else
            <div id="feedback-detail">
                @include('tools.feedback._detail')
            </div>
        @endif
    @endif
</x-layouts.app>
