{{--
    A chart in a card, with freshness and the five states. The page's script
    draws into the canvas with window.themedChart(el, config).

    <x-chart-card id="units-chart" title="Units by week" :state="$state" :updated-at="$updated" :from="$from" :to="$to"
                  summary="Weekly shipped units for the last 13 weeks." />
--}}
@props(['id', 'title', 'state' => 'complete', 'summary' => null, 'updatedAt' => null, 'from' => null, 'to' => null, 'staleAfterHours' => null, 'empty' => 'No data for this period.', 'message' => null])
<x-card :title="$title" {{ $attributes }}>
    <x-slot:actions>
        <x-freshness-badge :updated-at="$updatedAt" :from="$from" :to="$to" :stale-after-hours="$staleAfterHours" />
    </x-slot:actions>
    <x-data-state :state="$state" :empty="$empty" :message="$message">
        <div class="relative h-72">
            <canvas id="{{ $id }}" role="img" aria-label="{{ $summary ?? $title }}"></canvas>
        </div>
        {{ $slot }}
    </x-data-state>
</x-card>
