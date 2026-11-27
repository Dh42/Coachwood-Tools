/*
 * ASP Tracker page. Ported from the old page's inline script; the behaviour
 * is the same, the look comes from the shared chart theme:
 * - product search narrows the product list as you type; picking a new
 *   product clears the variant
 * - two charts sharing the x-axis, one y-scale each ($ and units never share
 *   an axis): ASP, product ASP (dotted) and list ASP (dashed) as lines, net
 *   units as bars. Every value is also in the tooltip and the tables, so the
 *   line styles, not only colour, tell the series apart
 * - clicking a point, a bar or a period row opens the drill-down: which
 *   discounts were behind that period (fetched from /drilldown)
 *
 * Page data comes from <script type="application/json" id="asp-config">.
 */
import { themedChart } from '../chart-theme';

const configEl = document.getElementById('asp-config');
if (configEl) {
    init(JSON.parse(configEl.textContent));
}

function init({ periods, drilldownUrl, t }) {
    const money = (v) => (v === null || v === undefined ? '—' : '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    const pct = (v) => (v === null || v === undefined ? '—' : (v * 100).toFixed(1) + '%');
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

    // Product search: narrows the select's options as you type.
    const search = document.getElementById('asp-product-search');
    const productSelect = document.getElementById('asp-product');
    search?.addEventListener('input', () => {
        const needle = search.value.trim().toLowerCase();
        for (const option of productSelect.options) {
            option.hidden = option.value !== '' && needle !== '' && !option.text.toLowerCase().includes(needle);
        }
    });
    // A new product makes the old variant meaningless.
    productSelect?.addEventListener('change', () => {
        const variant = document.getElementById('asp-variant');
        if (variant) variant.value = '';
    });

    // "Exactly how each number is worked out" opens the details card.
    document.querySelectorAll('[data-open-details]').forEach((link) => {
        link.addEventListener('click', () => {
            const details = document.getElementById(link.dataset.openDetails);
            if (details) details.open = true;
        });
    });

    // Drill-down: which discounts were active in a period.
    const modal = document.getElementById('asp-drilldown');
    const body = document.getElementById('asp-drilldown-body');
    const title = document.getElementById('asp-drilldown-title');

    async function openDrilldown(period) {
        const p = periods.find((x) => x.period === period);
        title.textContent = p ? p.label : period;
        body.innerHTML = '<p class="muted">' + esc(t.loading) + '</p>';
        modal.showModal();
        try {
            const url = drilldownUrl + (drilldownUrl.includes('?') ? '&' : '?') + 'period=' + encodeURIComponent(period);
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(String(res.status));
            const data = await res.json();
            const s = data.summary;
            let html = '<p class="text-sm">' + esc(t.asp) + ' <strong>' + money(s.asp) + '</strong> · ' + esc(t.productAsp) + ' ' + money(s.product_asp) + ' · ' + esc(t.listAsp) + ' ' + money(s.list_asp)
                + ' · ' + esc(t.depth) + ' ' + pct(s.discount_depth) + ' · ' + esc(t.units) + ' ' + Number(s.net_qty).toLocaleString() + '</p>';
            if (!data.groups.length) {
                html += '<p class="muted">' + esc(t.none) + '</p>';
            } else {
                html += '<div class="table-wrap"><table class="table"><thead><tr><th scope="col">' + esc(t.group) + '</th><th scope="col">' + esc(t.level)
                    + '</th><th scope="col" class="num">' + esc(t.lines) + '</th><th scope="col" class="num">' + esc(t.units) + '</th><th scope="col" class="num">' + esc(t.amount) + '</th></tr></thead><tbody>';
                for (const g of data.groups) {
                    const codes = g.codes > 1 ? ' <span class="muted text-xs">(' + g.codes.toLocaleString() + ' ' + esc(t.codes) + ')</span>' : '';
                    html += '<tr><td>' + esc(g.label) + codes + '</td><td>' + esc(t.levels[g.level] ?? g.level) + '</td><td class="num">' + g.lines.toLocaleString()
                        + '</td><td class="num">' + g.units.toLocaleString() + '</td><td class="num">' + money(g.amount) + '</td></tr>';
                }
                html += '</tbody></table></div>';
            }
            body.innerHTML = html;
        } catch {
            body.innerHTML = '<p class="field-error">' + esc(t.failed) + '</p>';
        }
    }

    document.querySelectorAll('tr[data-period]').forEach((row) => {
        row.addEventListener('click', () => openDrilldown(row.dataset.period));
        row.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openDrilldown(row.dataset.period);
            }
        });
    });

    const priceCanvas = document.getElementById('asp-price-chart');
    const unitsCanvas = document.getElementById('asp-units-chart');
    if (!priceCanvas || !unitsCanvas) return;

    const labels = periods.map((p) => p.label);
    const tooltipLines = (i) => {
        const p = periods[i];
        const lines = [
            t.asp + ': ' + money(p.asp),
            t.productAsp + ': ' + money(p.product_asp),
            t.listAsp + ': ' + money(p.list_asp),
            t.units + ': ' + p.net_qty.toLocaleString(),
            t.depth + ': ' + pct(p.discount_depth),
        ];
        if (p.top_labels.length) {
            lines.push(t.top + ':');
            p.top_labels.forEach((l) => lines.push('  ' + l.label + ' ' + money(l.amount)));
        }
        return lines;
    };
    const onClick = (_event, elements) => {
        if (elements.length) openDrilldown(periods[elements[0].index].period);
    };
    // One tooltip block per period (not one line per dataset).
    const tooltip = {
        displayColors: false,
        filter: (item) => item.datasetIndex === 0,
        callbacks: {
            title: (items) => (items.length ? periods[items[0].dataIndex].label : ''),
            label: (item) => tooltipLines(item.dataIndex),
        },
    };
    const dense = periods.length > 60;

    themedChart(priceCanvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: t.asp, data: periods.map((p) => p.asp), borderWidth: 2, tension: 0.2, pointRadius: dense ? 0 : 3, spanGaps: true },
                { label: t.productAsp, data: periods.map((p) => p.product_asp), borderWidth: 2, borderDash: [2, 3], tension: 0.2, pointRadius: 0, spanGaps: true },
                { label: t.listAsp, data: periods.map((p) => p.list_asp), borderWidth: 2, borderDash: [6, 4], tension: 0.2, pointRadius: 0, spanGaps: true },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            onClick,
            plugins: { legend: { display: true, labels: { usePointStyle: true } }, tooltip },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0 } },
                y: { ticks: { callback: (v) => '$' + v } },
            },
        },
    });

    themedChart(unitsCanvas, {
        type: 'bar',
        data: { labels, datasets: [{ label: t.units, data: periods.map((p) => p.net_qty), borderRadius: 4 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            onClick,
            plugins: { legend: { display: false }, tooltip },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0 } },
                y: { beginAtZero: true, ticks: { maxTicksLimit: 4 } },
            },
        },
    });
}
