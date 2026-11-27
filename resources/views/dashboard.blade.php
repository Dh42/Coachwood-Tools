{{--
    The home launcher (the old app's dashboard): every tool the user can
    open, grouped as in the design's "All tools". The design's "Needs
    attention" and KPI rows come later, from the tools themselves.
--}}
@php
    $toolCount = collect($groups)->flatten(1)->count();
@endphp
<x-layouts.app title="Coachwood Tools" breadcrumb="Home">
    <x-slot:subtitle>The tools you can open, grouped by area.</x-slot:subtitle>

    <section class="section" aria-labelledby="all-tools">
        <div class="section-head">
            <h2 class="section-title" id="all-tools">All tools</h2>
            @if ($toolCount)
                <span class="label">{{ trans_choice(':count tool|:count tools', $toolCount) }} · {{ trans_choice(':count group|:count groups', count($groups)) }}</span>
            @endif
        </div>

        @forelse ($groups as $title => $tools)
            <div class="flex flex-col gap-3">
                <h3 class="label">{{ $title }}</h3>
                <div class="tile-grid">
                    @foreach ($tools as $tool)
                        <x-tool-tile :icon="$tool->icon" :label="$tool->name" :href="$tool->url ? url($tool->url) : null" :description="$tool->description" />
                    @endforeach
                </div>
            </div>
        @empty
            <x-card>
                <x-empty-state title="No tools yet" icon="box">
                    Tools move here from tools.biosteel.com one at a time. Ask an admin if you expect to see one.
                </x-empty-state>
            </x-card>
        @endforelse
    </section>
</x-layouts.app>
