/*
 * BioSteel Sponsorship Stats charts, ported from the old page's inline
 * script onto the shared chart theme (which also recolours them when the
 * theme changes):
 * - sponsorships per month, as bars
 * - the top 10 requesters, as horizontal bars
 * - products sent per month, stacked by product, one series colour each
 * Every value is also in the tables or tooltips.
 *
 * Page data comes from <script type="application/json" id="tss-config">.
 */
import { themedChart } from '../chart-theme';

const configEl = document.getElementById('tss-config');
if (configEl) {
    init(JSON.parse(configEl.textContent));
}

function init({ months, requesters, products, t }) {
    const monthsCanvas = document.getElementById('tss-months-chart');
    if (monthsCanvas) {
        themedChart(monthsCanvas, {
            type: 'bar',
            data: { labels: months.labels, datasets: [{ label: t.sponsorships, data: months.values, borderRadius: 4, maxBarThickness: 40 }] },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }

    const requestersCanvas = document.getElementById('tss-requesters-chart');
    if (requestersCanvas) {
        themedChart(requestersCanvas, {
            type: 'bar',
            data: { labels: requesters.labels, datasets: [{ label: t.sponsorships, data: requesters.values, borderRadius: 4, maxBarThickness: 24 }] },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                interaction: { mode: 'index', axis: 'y', intersect: false },
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 } },
                    // Long emails are shortened on the axis; the tooltip has the whole address.
                    y: { grid: { display: false }, ticks: { callback(value) { const label = this.getLabelForValue(value); return label.length > 24 ? label.slice(0, 23) + '…' : label; } } },
                },
            },
        });
    }

    const productsCanvas = document.getElementById('tss-products-chart');
    if (productsCanvas) {
        themedChart(productsCanvas, {
            type: 'bar',
            data: {
                labels: products.months,
                datasets: Object.entries(products.series).map(([label, data]) => ({ label, data, borderRadius: 4, stack: 'products' })),
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }
}
