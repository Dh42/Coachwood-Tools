/*
 * BioSteel App Stats page. Ported from the old page's inline script; the
 * behaviour is the same, the look comes from the design tokens:
 * - charts use the shared chart theme (resources/js/chart-theme.js) and
 *   recolour on `theme-changed` in place, never destroyed and recreated
 * - the display multiplier (Settings): a cosmetic factor for screenshots,
 *   applied to every raw count from its data-raw value (never compounding)
 *   and never to ratios; kept in the `tas_stats_multiplier` cookie
 * - GA4, the QR campaign and Social Insights load live from the JSON
 *   endpoints; GA4 realtime refreshes every minute
 * - PDF export (html2canvas + jsPDF, loaded only when asked for) and a
 *   one-row CSV export, both of what's on screen
 *
 * Page data comes from <script type="application/json" id="tas-config">.
 */
import { chartTheme, onThemeChange, themedChart } from '../chart-theme';

const configEl = document.getElementById('tas-config');
const CONFIG = configEl ? JSON.parse(configEl.textContent) : null;

if (CONFIG) {
    init(CONFIG);
}

function init({ chart: D, range: RANGE, routes: ROUTES, granularity: GRANULARITY }) {
    const $ = (id) => document.getElementById(id);
    const toast = (message, tone) => window.toast?.(message, tone);

    // ---- Display multiplier ---------------------------------------------
    const MULTIPLIER_COOKIE = 'tas_stats_multiplier';

    function readMultiplierCookie() {
        const match = document.cookie.match(new RegExp(`(?:^|; )${MULTIPLIER_COOKIE}=([^;]*)`));
        const parsed = match ? parseFloat(decodeURIComponent(match[1])) : NaN;
        return Number.isFinite(parsed) && parsed >= 0 ? parsed : 1;
    }

    function writeMultiplierCookie(m) {
        document.cookie = `${MULTIPLIER_COOKIE}=${encodeURIComponent(m)};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`;
    }

    let multiplier = readMultiplierCookie();

    const scaled = (arr, m) => (arr || []).map((v) => (v === null ? null : Math.round(v * m)));

    // ---- Charts ---------------------------------------------------------
    // Labels are "Y-m-d H:i:s"; hourly points show "m-d H:i", daily and weekly ones the date.
    const shortLabel = (label) => (GRANULARITY === 'raw' ? String(label).slice(5, 16) : String(label).slice(0, 10));
    const timeTicks = {
        maxTicksLimit: 8,
        maxRotation: 0,
        callback(value) {
            return shortLabel(this.getLabelForValue(value));
        },
    };

    const axes = (indexAxis = 'x', thousands = true, timeAxis = false) => ({
        x: {
            beginAtZero: indexAxis === 'y',
            grid: { display: indexAxis === 'y' },
            ticks: indexAxis === 'y' && thousands
                ? { callback: (v) => v.toLocaleString() }
                : (timeAxis ? timeTicks : { maxTicksLimit: 8, maxRotation: 0 }),
        },
        y: {
            beginAtZero: indexAxis !== 'y',
            grid: { display: indexAxis !== 'y' },
            ticks: indexAxis !== 'y' && thousands ? { callback: (v) => v.toLocaleString() } : {},
        },
    });

    function lineChart(id, values) {
        const el = $(id);
        if (!el || !D.labels.length) return null;
        return themedChart(el, {
            type: 'line',
            data: { labels: D.labels, datasets: [{ data: values, borderWidth: 2, tension: 0.25, pointRadius: 0, fill: false }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                // Totals in the tens of thousands growing by a few dozen a day:
                // the axis follows the data, as the old charts did, so the
                // growth is visible. Bars still start at zero.
                scales: (() => {
                    const scales = axes('x', true, true);
                    scales.y.beginAtZero = false;
                    return scales;
                })(),
            },
        });
    }

    function barChart(id, labels, values, indexAxis = 'x') {
        const el = $(id);
        if (!el) return null;
        return themedChart(el, {
            type: 'bar',
            data: { labels, datasets: [{ data: values, borderRadius: 3, maxBarThickness: indexAxis === 'y' ? 22 : 40 }] },
            options: {
                indexAxis,
                responsive: true,
                maintainAspectRatio: false,
                // Room for long category names on the left of horizontal bars.
                layout: { padding: { left: indexAxis === 'y' ? 8 : 0 } },
                plugins: { legend: { display: false } },
                scales: axes(indexAxis),
            },
        });
    }

    // Gains green, losses red: one colour per bar, so it's set here and on theme change.
    const growthColors = (values) => {
        const theme = chartTheme();
        return values.map((v) => (v < 0 ? theme.bad : theme.good));
    };

    const usersChart = lineChart('tas-users-chart', scaled(D.users, multiplier));
    const teamsChart = lineChart('tas-teams-chart', scaled(D.teams, multiplier));

    let growthChart = null;
    if ($('tas-growth-chart') && D.labels.length) {
        const values = scaled(D.usersGrowth, multiplier);
        growthChart = themedChart($('tas-growth-chart'), {
            type: 'bar',
            data: { labels: D.labels, datasets: [{ data: values, fixedColor: true, backgroundColor: growthColors(values) }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: axes('x', false, true) },
        });
        onThemeChange(() => {
            growthChart.data.datasets[0].backgroundColor = growthColors(growthChart.data.datasets[0].data);
            growthChart.update('none');
        });
    }

    const sportChart = D.catLabels.length ? barChart('tas-sport-chart', D.catLabels, scaled(D.catValues, multiplier), 'y') : null;

    let adsChart = null;
    if ($('tas-ads-chart') && D.adLabels.length) {
        adsChart = themedChart($('tas-ads-chart'), {
            type: 'bar',
            data: {
                labels: D.adLabels,
                datasets: [
                    { label: 'Ad requests', data: scaled(D.adRequests, multiplier), borderRadius: 3 },
                    { label: 'Impressions', data: scaled(D.adImpressions, multiplier), borderRadius: 3 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { boxWidth: 12, usePointStyle: true } } },
                scales: axes(),
            },
        });
    }

    // GA4's three charts are created empty once, then fed each time new data arrives.
    const ga4AgeChart = barChart('tas-ga4-age-chart', [], [], 'y');
    const ga4GenderChart = barChart('tas-ga4-gender-chart', [], [], 'y');
    const ga4CountryChart = barChart('tas-ga4-country-chart', [], [], 'y');

    function setChart(chart, labels, values) {
        if (!chart) return;
        chart.data.labels = labels;
        chart.data.datasets[0].data = values;
        chart.update();
    }

    function applyMultiplierToCharts(m) {
        if (usersChart) setChart(usersChart, D.labels, scaled(D.users, m));
        if (teamsChart) setChart(teamsChart, D.labels, scaled(D.teams, m));
        if (growthChart) {
            const values = scaled(D.usersGrowth, m);
            growthChart.data.datasets[0].data = values;
            growthChart.data.datasets[0].backgroundColor = growthColors(values);
            growthChart.update();
        }
        if (sportChart) setChart(sportChart, D.catLabels, scaled(D.catValues, m));
        if (adsChart) {
            adsChart.data.datasets[0].data = scaled(D.adRequests, m);
            adsChart.data.datasets[1].data = scaled(D.adImpressions, m);
            adsChart.update();
        }
    }

    // ---- Multiplier on server-rendered numbers ---------------------------
    const readRaw = (el) => (el.dataset.raw === 'null' ? null : parseFloat(el.dataset.raw));
    const fmtInt = (raw, m) => (raw === null || Number.isNaN(raw) ? '—' : Math.round(raw * m).toLocaleString());
    const fmtSigned = (raw, m) => {
        if (raw === null || Number.isNaN(raw)) return '—';
        const v = Math.round(raw * m);
        return (v >= 0 ? '+' : '') + v.toLocaleString();
    };

    function applyMultiplierToDom(m) {
        document.querySelectorAll('.mult-int').forEach((el) => {
            el.textContent = fmtInt(readRaw(el), m);
        });
        document.querySelectorAll('.mult-signed').forEach((el) => {
            const raw = readRaw(el);
            el.textContent = fmtSigned(raw, m);
            if (raw !== null) {
                el.classList.toggle('delta-up', raw * m >= 0);
                el.classList.toggle('delta-down', raw * m < 0);
            }
        });
        document.querySelectorAll('.mult-decimal1').forEach((el) => {
            const raw = readRaw(el);
            el.textContent = raw === null || Number.isNaN(raw) ? '' : (raw * m).toFixed(1);
        });
    }

    if (multiplier !== 1) applyMultiplierToDom(multiplier);

    // ---- Shared rendering helpers -----------------------------------------
    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s ?? '';
        return div.innerHTML;
    }

    const NO_DATA_ROW = (cols) => `<tr><td colspan="${cols}" class="muted">No data</td></tr>`;

    function renderRows(tbodyId, rows, m) {
        const tbody = $(tbodyId);
        if (!tbody) return;
        tbody.innerHTML = rows.length
            ? rows.map((r) => `<tr><td>${escapeHtml(r.name)}</td><td class="num">${Math.round(r.value * m).toLocaleString()}</td></tr>`).join('')
            : NO_DATA_ROW(2);
    }

    // Shares are ratios: never multiplied, and the server sends no raw counts for them.
    function renderShareRows(tbodyId, rows) {
        const tbody = $(tbodyId);
        if (!tbody) return;
        const fmt = (pct) => (pct > 0 && pct < 0.1 ? '<0.1%' : `${pct.toFixed(1)}%`);
        tbody.innerHTML = rows.length
            ? rows.map((r) => `<tr><td>${escapeHtml(r.name)}${r.detail ? ` <span class="faint text-xs">· ${escapeHtml(r.detail)}</span>` : ''}</td><td class="num">${fmt(r.pct)}</td></tr>`).join('')
            : NO_DATA_ROW(2);
    }

    // "+12.3% vs previous period" from the raw values (unaffected by the multiplier).
    function compareHtml(current, previous) {
        if (current === null || current === undefined || !previous) return '';
        const pct = ((current - previous) / previous) * 100;
        return `<span class="${pct >= 0 ? 'delta-up' : 'delta-down'}">${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%</span> vs previous period`;
    }

    const prevTitle = (data) => (data.prevRange ? `Previous period: ${data.prevRange.from} to ${data.prevRange.to}` : '');

    // ---- GA4 (live, never stored: GA4 de-duplicates users per query window) ----
    let ga4Cache = null;

    function ga4RenderAll(data) {
        ga4Cache = data;
        const m = multiplier;
        const scaleN = (n) => (n === null || n === undefined ? null : Math.round(n * m));

        $('tas-ga4-events').textContent = data.events !== null ? scaleN(data.events).toLocaleString() : '—';
        $('tas-kpi-ga4-users').textContent = data.activeUsers !== null ? scaleN(data.activeUsers).toLocaleString() : '—';

        const eventsSub = $('tas-ga4-events-sub');
        eventsSub.innerHTML = compareHtml(data.events, data.eventsPrev);
        eventsSub.title = prevTitle(data);
        const usersSub = $('tas-kpi-ga4-users-sub');
        usersSub.innerHTML = compareHtml(data.activeUsers, data.activeUsersPrev);
        usersSub.title = prevTitle(data);

        const platform = (data.platform || []).map((p) => ({ name: p.name, value: scaleN(p.value) }));
        const platformTotal = platform.reduce((sum, p) => sum + p.value, 0);
        $('tas-ga4-platform').textContent = platform.length
            ? platform.map((p) => `${p.name} ${platformTotal ? Math.round((p.value / platformTotal) * 100) : 0}%`).join(' · ')
            : '—';
        $('tas-ga4-platform-sub').textContent = platform.map((p) => `${p.name}: ${p.value.toLocaleString()}`).join(' · ');

        const names = (rows) => (rows || []).map((r) => r.name);
        const values = (rows) => (rows || []).map((r) => Math.round(r.value * m));
        setChart(ga4AgeChart, names(data.age), values(data.age));
        setChart(ga4GenderChart, names(data.gender), values(data.gender));
        setChart(ga4CountryChart, names(data.country), values(data.country));

        renderRows('tas-ga4-country-rows', data.country || [], m);
        renderShareRows('tas-ga4-city-share-rows', data.cityShares || []);
        renderShareRows('tas-ga4-region-share-rows', data.regionShares || []);
        renderRows('tas-ga4-interest-rows', data.interests || [], m);

        $('tas-ga4-body').hidden = false;
        $('tas-ga4-error').hidden = true;
    }

    function ga4ShowError() {
        ['tas-ga4-events', 'tas-ga4-platform', 'tas-kpi-ga4-users'].forEach((id) => { $(id).textContent = '—'; });
        ['tas-ga4-platform-sub', 'tas-ga4-events-sub', 'tas-kpi-ga4-users-sub'].forEach((id) => { $(id).textContent = ''; });
        $('tas-ga4-body').hidden = true;
        $('tas-ga4-error').hidden = false;
    }

    const rangeQuery = `from=${encodeURIComponent(RANGE.from)}&to=${encodeURIComponent(RANGE.to)}`;
    const getJson = (url) => fetch(url, { headers: { Accept: 'application/json' } }).then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
    });

    if ($('tas-ga4-cards')) {
        getJson(`${ROUTES.ga4Data}?${rangeQuery}`).then(ga4RenderAll).catch(ga4ShowError);
    }

    let realtimeRaw = null;

    function renderRealtime() {
        const el = $('tas-ga4-realtime');
        if (el && realtimeRaw !== null) el.textContent = Math.round(realtimeRaw * multiplier).toLocaleString();
    }

    function loadRealtime() {
        const el = $('tas-ga4-realtime');
        if (!el) return;
        getJson(ROUTES.ga4Realtime)
            .then((data) => { realtimeRaw = data.activeUsers; renderRealtime(); })
            .catch(() => { el.textContent = '—'; });
    }

    loadRealtime();
    if ($('tas-ga4-realtime')) setInterval(loadRealtime, 60000);

    // ---- QR-code sponsorship campaign (a separate GA4 property) ------------
    let sponsorCache = null;

    function sponsorRenderAll(data) {
        sponsorCache = data;
        const scaleN = (n) => Math.round(n * multiplier).toLocaleString();
        const fields = [
            ['users', 'activeUsers'],
            ['sessions', 'sessions'],
            ['engaged', 'engagedSessions'],
            ['key-events', 'keyEvents'],
        ];
        fields.forEach(([id, key]) => {
            $(`tas-sponsor-${id}`).textContent = scaleN(data[key]);
            const sub = $(`tas-sponsor-${id}-sub`);
            sub.innerHTML = compareHtml(data[key], data[`${key}Prev`]);
            sub.title = prevTitle(data);
        });
        $('tas-ga4-sponsor-error').hidden = true;
    }

    function sponsorShowError() {
        ['users', 'sessions', 'engaged', 'key-events'].forEach((id) => {
            $(`tas-sponsor-${id}`).textContent = '—';
            $(`tas-sponsor-${id}-sub`).textContent = '';
        });
        $('tas-ga4-sponsor-error').hidden = false;
    }

    if ($('tas-ga4-sponsor-cards')) {
        getJson(`${ROUTES.ga4SponsorData}?${rangeQuery}`).then(sponsorRenderAll).catch(sponsorShowError);
    }

    // ---- Settings: apply a new multiplier everywhere -------------------------
    function applyMultiplier(rawInputValue) {
        const parsed = parseFloat(rawInputValue);
        multiplier = Number.isFinite(parsed) && parsed >= 0 ? parsed : 1;
        writeMultiplierCookie(multiplier);

        applyMultiplierToDom(multiplier);
        applyMultiplierToCharts(multiplier);
        if (ga4Cache) ga4RenderAll(ga4Cache);
        if (sponsorCache) sponsorRenderAll(sponsorCache);
        renderRealtime();
    }

    const multiplierInput = $('tas-multiplier-input');
    document.querySelector('[data-modal-open="tas-settings-modal"]')?.addEventListener('click', () => {
        if (multiplierInput) multiplierInput.value = multiplier;
    });
    $('tas-multiplier-ok')?.addEventListener('click', () => {
        applyMultiplier(multiplierInput.value);
        $('tas-settings-modal')?.close();
    });

    // ---- PDF export: a picture of the page as it is on screen ---------------
    const allCharts = () => [usersChart, teamsChart, growthChart, sportChart, adsChart, ga4AgeChart, ga4GenderChart, ga4CountryChart].filter(Boolean);
    const pdfButton = $('tas-export-pdf-btn');

    async function exportPdf() {
        const main = document.getElementById('main');
        pdfButton.disabled = true;
        pdfButton.setAttribute('aria-busy', 'true');
        document.body.classList.add('tas-exporting');

        try {
            const [{ default: html2canvas }, { jsPDF }] = await Promise.all([import('html2canvas'), import('jspdf')]);
            allCharts().forEach((c) => c.resize());
            await new Promise(requestAnimationFrame);
            await new Promise(requestAnimationFrame);

            const canvas = await html2canvas(main, {
                backgroundColor: getComputedStyle(document.body).backgroundColor,
                scale: 1.5,
                scrollX: 0,
                scrollY: -window.scrollY,
                useCORS: true,
            });

            // Points, not px: 72 pt per 96 CSS px keeps page and image in step.
            const PX_TO_PT = 72 / 96;
            const width = canvas.width * PX_TO_PT;
            const height = canvas.height * PX_TO_PT;
            const pdf = new jsPDF({ orientation: width >= height ? 'landscape' : 'portrait', unit: 'pt', format: [width, height] });
            // JPEG: a lossless page render runs to tens of MB for no visible gain.
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, width, height);
            pdf.save(`team-app-stats_${RANGE.from}_to_${RANGE.to}.pdf`);
        } catch (error) {
            console.error(error);
            toast('Could not generate the PDF. Please try again.', 'bad');
        } finally {
            document.body.classList.remove('tas-exporting');
            allCharts().forEach((c) => c.resize());
            pdfButton.removeAttribute('aria-busy');
            pdfButton.disabled = false;
        }
    }

    pdfButton?.addEventListener('click', exportPdf);

    // Labels are measured when a chart is drawn; redraw once the web font has
    // loaded, or long category names get clipped.
    document.fonts?.ready.then(() => allCharts().forEach((c) => c.update('none')));

    // ---- CSV export: one header row and one data row of what's on screen ----
    // Meant to be pasted as a new row into a running sheet, so values are
    // plain numbers (no commas or %), and range-relative growth is left out.
    const csvEscape = (value) => {
        const s = String(value ?? '');
        return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const textOf = (id) => $(id)?.textContent.trim() ?? '';
    const plainNumber = (text) => {
        const cleaned = String(text ?? '').replace(/[^0-9.-]/g, '');
        return cleaned === '' || cleaned === '-' ? '' : cleaned;
    };

    function exportCsv() {
        const granularity = document.querySelector('input[name="g"]:checked')?.value ?? '';
        const sports = Array.from(document.querySelectorAll('#tas-sport-table tbody tr')).map((tr) => {
            const cells = tr.querySelectorAll('td');
            return [cells[0]?.textContent.trim() || '', plainNumber(cells[1]?.textContent.trim())];
        }).filter(([name]) => name !== '');

        const header = [
            'From', 'To', 'Granularity', 'Users now', 'Teams now',
            'Impressions (range)', 'Ad requests (range)', 'Fill rate %', 'Clicks (range)', 'CTR %',
            'Viewable impressions', 'Viewability %', 'Events (range)', 'Active users (range)', 'Active right now',
            ...sports.map(([name]) => `Teams: ${name}`),
        ];
        const row = [
            RANGE.from, RANGE.to, granularity,
            plainNumber(textOf('tas-stat-users-now')), plainNumber(textOf('tas-stat-teams-now')),
            plainNumber(textOf('tas-ad-impressions')), plainNumber(textOf('tas-ad-requests')), plainNumber(textOf('tas-ad-fill-rate')),
            plainNumber(textOf('tas-ad-clicks')), plainNumber(textOf('tas-ad-ctr')),
            plainNumber(textOf('tas-ad-viewable')), plainNumber(textOf('tas-ad-viewability')),
            plainNumber(textOf('tas-ga4-events')), plainNumber(textOf('tas-kpi-ga4-users')), plainNumber(textOf('tas-ga4-realtime')),
            ...sports.map(([, count]) => count),
        ];

        const csv = [header, row].map((r) => r.map(csvEscape).join(',')).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        const a = Object.assign(document.createElement('a'), { href: url, download: `team-app-stats_${RANGE.from}_to_${RANGE.to}.csv` });
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    $('tas-export-csv-btn')?.addEventListener('click', exportCsv);

    // ---- Social media: live leaderboards for the chosen period --------------
    function authorCell(row) {
        const who = row.teamName ? row.teamName : (row.authorName || row.authorUsername || '—');
        const sub = row.teamName ? (row.authorName || '') : (row.authorUsername ? `@${row.authorUsername}` : '');
        return `${escapeHtml(who)}${sub ? `<div class="faint text-xs">${escapeHtml(sub)}</div>` : ''}`;
    }

    function renderPosts(tbodyId, rows) {
        $(tbodyId).innerHTML = rows.map((r) => {
            const older = r.publishedInPeriod === false
                ? ' <span class="tone tone-mute" title="Published before this period — still picked up new activity">older post</span>'
                : '';
            return `<tr>
                <td>${r.rank ?? ''}</td>
                <td>${authorCell(r)}</td>
                <td><span class="tone tone-info">${escapeHtml(r.postType || '')}</span>${older}</td>
                <td class="num">${(r.reactionsInPeriod ?? 0).toLocaleString()}</td>
                <td class="num">${(r.commentsInPeriod ?? 0).toLocaleString()}</td>
                <td class="num font-semibold">${(r.interactionsInPeriod ?? 0).toLocaleString()}</td>
            </tr>`;
        }).join('');
    }

    function renderFollowed(tbodyId, rows) {
        $(tbodyId).innerHTML = rows.map((r) => `<tr>
            <td>${r.rank ?? ''}</td>
            <td>${escapeHtml(r.displayName || r.username || '—')}<div class="faint text-xs">${escapeHtml(r.accountType || '')}${r.sportType ? ` · ${escapeHtml(r.sportType)}` : ''}</div></td>
            <td class="num font-semibold">+${(r.newFollowers ?? 0).toLocaleString()}</td>
            <td class="num">${(r.totalFollowers ?? 0).toLocaleString()}</td>
        </tr>`).join('');
    }

    const currentPeriod = () => document.querySelector('input[name="social-period"]:checked')?.value ?? 'WEEK';

    function loadLeaderboard(prefix, route, render) {
        const loading = $(`tas-social-${prefix}-loading`);
        if (!loading) return;
        const error = $(`tas-social-${prefix}-error`);
        const empty = $(`tas-social-${prefix}-empty`);
        const table = $(`tas-social-${prefix}-table`);
        loading.hidden = false;
        error.hidden = true;
        empty.hidden = true;
        table.hidden = true;

        getJson(`${route}?period=${encodeURIComponent(currentPeriod())}`)
            .then((body) => {
                loading.hidden = true;
                if (!body.data || !body.data.length) {
                    empty.hidden = false;
                    return;
                }
                render(`tas-social-${prefix}-rows`, body.data);
                table.hidden = false;
            })
            .catch(() => {
                loading.hidden = true;
                error.hidden = false;
            });
    }

    function loadSocialLive() {
        loadLeaderboard('posts', ROUTES.socialTopPosts, renderPosts);
        loadLeaderboard('followed', ROUTES.socialTopFollowed, renderFollowed);
    }

    document.querySelectorAll('input[name="social-period"]').forEach((input) => input.addEventListener('change', loadSocialLive));
    loadSocialLive();

    // ---- Social history: a past day's stored (WEEK) leaderboards -------------
    function loadHistory(date) {
        getJson(`${ROUTES.socialHistory}?date=${encodeURIComponent(date)}`)
            .then((data) => {
                renderPosts('tas-social-history-posts-rows', (data.posts || []).map((p) => ({
                    rank: p.rank, teamName: p.team_name, authorName: p.author_name, authorUsername: p.author_username,
                    postType: p.post_type, publishedInPeriod: p.published_in_period,
                    reactionsInPeriod: p.reactions_in_period, commentsInPeriod: p.comments_in_period, interactionsInPeriod: p.interactions_in_period,
                })));
                renderFollowed('tas-social-history-followed-rows', (data.followed || []).map((f) => ({
                    rank: f.rank, displayName: f.display_name, username: f.username, accountType: f.account_type,
                    sportType: f.sport_type, newFollowers: f.new_followers, totalFollowers: f.total_followers,
                })));
                $('tas-social-history-body').hidden = false;
                $('tas-social-history-empty').hidden = true;
            })
            .catch(() => {});
    }

    const historySelect = $('tas-social-history-date');
    if (historySelect) {
        historySelect.addEventListener('change', (event) => loadHistory(event.target.value));
        getJson(ROUTES.socialHistory)
            .then((data) => {
                if (!data.dates || !data.dates.length) return;
                historySelect.innerHTML = data.dates.map((d) => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join('');
                historySelect.hidden = false;
                loadHistory(data.date);
            })
            .catch(() => {});
    }
}
