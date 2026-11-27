{{--
    Every data page shows what period it covers and when it was last updated
    (spec 15, rule 5). Pass the ingestion ledger's values.

    <x-freshness-badge :updated-at="$run->finished_at" :from="$start" :to="$end" :stale-after-hours="26" />

    No updated-at: shows "Never updated" as a warning.
--}}
@props(['updatedAt' => null, 'from' => null, 'to' => null, 'staleAfterHours' => null, 'source' => null])
@php
    use Illuminate\Support\Carbon;

    $updated = $updatedAt ? Carbon::parse($updatedAt) : null;
    $stale = $updated && $staleAfterHours !== null && $updated->lt(now()->subHours($staleAfterHours));
    $tone = ! $updated ? 'warn' : ($stale ? 'warn' : 'good');
    $format = fn ($d) => Carbon::parse($d)->format('M j, Y');
    $period = match (true) {
        $from && $to => $format($from).' – '.$format($to),
        (bool) $from => 'From '.$format($from),
        (bool) $to => 'To '.$format($to),
        default => null,
    };
@endphp
<span {{ $attributes->class('inline-flex flex-wrap items-center gap-2') }} data-freshness>
    @if ($period)<span class="label">{{ $period }}</span>@endif
    <x-status-badge :tone="$tone" :icon="$stale || ! $updated ? 'alert' : 'clock'">
        @if ($updated)
            <time datetime="{{ $updated->toIso8601String() }}" title="{{ $updated->utc()->format('Y-m-d H:i') }} UTC">{{ $stale ? 'Stale · ' : '' }}Updated {{ $updated->diffForHumans() }}</time>
        @else
            Never updated
        @endif
        @if ($source)<span class="sr-only">from</span> · {{ $source }}@endif
    </x-status-badge>
</span>
