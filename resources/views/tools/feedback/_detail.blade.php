@php
    $catalogLabel = ['vc' => __('VC only'), 'sc' => __('SC only'), 'both' => __('Both')];
    $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1).'%';
    $impact = fn (?float $v) => $v === null ? '—' : ($v >= 0 ? '+' : '').number_format($v, 2);
    $num = fn ($v) => $v !== null ? (float) $v : null;
@endphp

@if (! $detail)
    <x-card>
        <x-empty-state :title="__('No data pulled yet')" icon="star">{{ __('No data pulled yet for :asin.', ['asin' => $selectedAsin]) }}</x-empty-state>
    </x-card>
@elseif ($detail['raw']->http_status != 200)
    <x-notice tone="info">
        {{ __('Last pull for :asin returned HTTP :status (:meaning) on :date.', [
            'asin' => $selectedAsin,
            'status' => $detail['raw']->http_status,
            'meaning' => $detail['raw']->http_status == 204 ? __('no content — not enough US reviews yet') : __('see raw response'),
            'date' => $detail['raw']->snapshot_date->toDateString(),
        ]) }}
    </x-notice>
@else
    <p class="muted text-sm mb-4">
        {{ __(':asin — snapshot :date, Amazon\'s own window :start to :end, catalog: :catalog', [
            'asin' => $selectedAsin, 'date' => $detail['raw']->snapshot_date->toDateString(),
            'start' => $detail['raw']->data_start_date?->toDateString() ?? '—', 'end' => $detail['raw']->data_end_date?->toDateString() ?? '—',
            'catalog' => $catalogLabel[$detail['raw']->source_catalog] ?? $detail['raw']->source_catalog,
        ]) }}
    </p>

    <div class="flex flex-col gap-4">
        @foreach ($detail['topics'] as $t)
            @php
                $negative = $t->polarity === 'negative';
                $trendPoints = $trendsByTopic[$t->polarity.'|'.$t->topic] ?? null;
            @endphp
            <section class="card topic-card {{ $negative ? 'topic-negative' : 'topic-positive' }}">
                <div class="card-body">
                    <div class="card-head">
                        <h2 class="card-title">{{ $t->topic }}</h2>
                        <x-status-badge :tone="$negative ? 'bad' : 'good'">{{ $negative ? __('Negative') : __('Positive') }}</x-status-badge>
                    </div>

                    <div class="topic-metrics">
                        <span><strong>{{ (int) $t->number_of_mentions }}</strong> {{ __('mentions') }}@if ($t->implied_review_count !== null) <span class="faint">(~{{ $t->implied_review_count }} {{ __('reviews') }})</span>@endif</span>
                        <span>{{ __('Occurrence:') }} <strong>{{ $pct($num($t->occurrence_percentage)) }}</strong> {{ __('vs. category') }} <strong>{{ $pct($num($t->browse_node_occurrence_pct)) }}</strong></span>
                        <span>{{ __('Star impact:') }} <strong>{{ $impact($num($t->star_rating_impact)) }}</strong> {{ __('vs. category') }} <strong>{{ $impact($num($t->browse_node_star_impact)) }}</strong></span>
                    </div>

                    @if ($t->parent_asin_mentions !== null || $t->child_most_mentions_asin !== null)
                        <div class="topic-metrics">
                            @if ($t->parent_asin_mentions !== null)
                                <span>{{ __('Parent ASIN:') }} <strong>{{ (int) $t->parent_asin_mentions }}</strong> {{ __('mentions,') }} {{ $pct($num($t->parent_asin_occurrence_pct)) }}</span>
                            @endif
                            @if ($t->child_most_mentions_asin !== null)
                                <span>{{ __('Most-mentioning sibling:') }} <strong>{{ $t->child_most_mentions_asin }}</strong> ({{ (int) $t->child_most_mentions_count }})</span>
                            @endif
                            @if ($t->child_least_mentions_asin !== null)
                                <span>{{ __('Least:') }} <strong>{{ $t->child_least_mentions_asin }}</strong> ({{ (int) $t->child_least_mentions_count }})</span>
                            @endif
                        </div>
                    @endif

                    @if ($trendPoints)
                        <div class="topic-trend">
                            <span class="label">{{ __('6-month trend:') }}</span>
                            @foreach ($trendPoints as $tp)
                                <x-status-badge tone="mute" :icon="false">{{ $tp->month_start->format('M') }} {{ $tp->asin_occurrence_pct !== null ? number_format((float) $tp->asin_occurrence_pct, 1).'%' : '—' }}</x-status-badge>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($t->snippets as $s)
                        <blockquote class="snippet">"{{ $s->snippet }}"</blockquote>
                    @endforeach

                    @foreach ($t->subtopics as $st)
                        <div class="subtopic">
                            <span>{{ $st->subtopic }}</span>
                            <span class="muted">{{ (int) $st->number_of_mentions }} {{ __('mentions,') }} {{ $pct($num($st->occurrence_percentage)) }}</span>
                        </div>
                        @foreach ($st->snippets as $s)
                            <blockquote class="snippet">"{{ $s->snippet }}"</blockquote>
                        @endforeach
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
