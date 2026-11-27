{{--
    Every shared component with invented sample values, for checking the
    shell and themes. Local and testing environments only (routes/web.php).
    No real data: every number here is made up.
--}}
<x-layouts.app title="Components" breadcrumb="Development">
    <x-slot:subtitle>Every shared component in every state, with made-up values.</x-slot:subtitle>
    <x-slot:actions>
        <button type="button" class="icon-btn" aria-label="Notifications"><x-icon name="bell" /></button>
        <button type="button" class="btn" data-modal-open="demo-modal">Open modal</button>
        <button type="button" class="btn btn-primary" onclick="window.toast('Saved', 'good')">Show toast</button>
    </x-slot:actions>

    <section class="section">
        <div class="section-head"><h2 class="section-title">Needs attention</h2><span class="label">Incident cards</span></div>
        <div class="tile-grid">
            <x-incident-card tone="bad" source="Price Monitor" :count="12" label="ASINs priced below cost" meta="Scan · 2 h ago" href="#" />
            <x-incident-card tone="near" source="PO Checker" :count="4" label="SKUs over forecast" meta="Sample" href="#" />
            <x-incident-card tone="info" source="PO Classification" :count="3" label="POs waiting on review" />
        </div>
    </section>

    <x-stat-row>
        <x-stat-tile label="Units accepted · 90d" value="1,234" sub="Sample value" />
        <x-stat-tile label="Sent to Amazon · 90d" value="$56,789" sub="Sample value" />
        <x-stat-tile label="Missing value" :value="null" sub="Shows No data, never a bare zero" />
    </x-stat-row>

    <x-card title="Status badges">
        <div class="flex flex-wrap gap-2">
            <x-status-badge tone="bad">Below cost</x-status-badge>
            <x-status-badge tone="near">Near cost</x-status-badge>
            <x-status-badge tone="warn">10% off</x-status-badge>
            <x-status-badge tone="gap">Regional gap</x-status-badge>
            <x-status-badge tone="info">Queued</x-status-badge>
            <x-status-badge tone="good">OK</x-status-badge>
            <x-status-badge tone="mute">Unknown</x-status-badge>
        </div>
        <div class="flex flex-wrap gap-3">
            <x-freshness-badge :updated-at="now()->subMinutes(40)" :from="now()->subDays(90)" :to="now()" :stale-after-hours="26" />
            <x-freshness-badge :updated-at="now()->subDays(3)" :stale-after-hours="26" source="Data Kiosk" />
            <x-freshness-badge />
        </div>
    </x-card>

    <x-card title="Tabs and filters">
        <x-tabs :tabs="['overview' => 'Overview', 'by-asin' => 'By ASIN', 'by-province' => 'By province']" :active="request('tab', 'overview')" />
        <x-filter-bar>
            <label class="field"><span>From</span><input class="input" type="date" name="from"></label>
            <label class="field"><span>To</span><input class="input" type="date" name="to"></label>
            <label class="field"><span>Vendor code</span>
                <select class="select" name="vendor"><option>All</option><option>Sample A</option></select>
            </label>
        </x-filter-bar>
    </x-card>

    <x-card title="Data table">
        <x-data-table caption="Sample products" csv="sample.csv" copy="asin">
            <thead><tr>
                <x-th sort column="asin">ASIN</x-th>
                <x-th sort>Product</x-th>
                <x-th sort type="number">Units</x-th>
                <x-th>Status</x-th>
            </tr></thead>
            <tbody>
                @foreach ([['B0SAMPLE01', 'Sample product one', 120, 'good', 'OK'], ['B0SAMPLE02', 'Sample product two', 8, 'bad', 'Below cost'], ['B0SAMPLE03', 'Sample product three', 45, 'warn', '10% off']] as [$asin, $name, $units, $tone, $status])
                    <tr>
                        <td class="mono">{{ $asin }}</td>
                        <td>{{ $name }}</td>
                        <td class="num" data-value="{{ $units }}">{{ number_format($units) }}</td>
                        <td><x-status-badge :tone="$tone">{{ $status }}</x-status-badge></td>
                    </tr>
                @endforeach
            </tbody>
        </x-data-table>
    </x-card>

    <section class="section">
        <div class="section-head"><h2 class="section-title">Data states</h2></div>
        <div class="tile-grid">
            <x-card title="Loading"><x-data-state state="loading" /></x-card>
            <x-card title="Empty"><x-data-state state="empty" empty="No POs uploaded yet." /></x-card>
            <x-card title="Error"><x-data-state state="error" /></x-card>
            <x-card title="Partial"><x-data-state state="partial" message="The last two days haven't arrived from Amazon yet."><p class="muted">Content still shows.</p></x-data-state></x-card>
        </div>
    </section>

    <x-chart-card id="demo-chart" title="Chart card" summary="Sample weekly units for three series." :updated-at="now()->subHour()" :from="now()->subWeeks(8)" :to="now()" />

    <section class="section">
        <div class="section-head"><h2 class="section-title">Tool tiles</h2></div>
        <div class="tile-grid">
            <x-tool-tile icon="box" label="PO Checker" href="#" description="Check a POItemExport against stock and export back." meta="Sample meta" />
            <x-tool-tile icon="phone" label="App Stats" href="#" description="Users, teams and ads for the Teams app." />
        </div>
    </section>

    <x-card title="Empty state with actions">
        <x-empty-state title="No tools here yet">
            Nothing to show.
            <x-slot:actions><a class="btn" href="#">Do something</a></x-slot:actions>
        </x-empty-state>
    </x-card>

    <x-modal id="demo-modal" title="Modal title">
        <p class="muted">A native dialog: Escape closes it and focus stays inside.</p>
        <x-slot:footer>
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="button" class="btn btn-primary" data-modal-close>Confirm</button>
        </x-slot:footer>
    </x-modal>

    <script type="module">
        document.addEventListener('DOMContentLoaded', () => {
            const weeks = Array.from({ length: 8 }, (_, i) => `W${i + 1}`);
            window.themedChart(document.getElementById('demo-chart'), {
                type: 'line',
                data: { labels: weeks, datasets: [
                    { label: 'Series A', data: [12, 15, 14, 18, 21, 19, 24, 26] },
                    { label: 'Series B', data: [8, 9, 12, 11, 13, 15, 14, 16] },
                    { label: 'Series C', data: [4, 6, 5, 7, 6, 8, 9, 8] },
                ] },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: {}, y: { beginAtZero: true } } },
            });
        });
    </script>
</x-layouts.app>
