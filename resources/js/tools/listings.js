/*
 * VC and SC Listings Editors, ported from the old pages' inline scripts and
 * listings-table-controls.js. The behaviour is the same:
 * - search, filter buttons and column sort over the listings table. A row
 *   and its open editor move and hide together.
 * - clicking a title or arrow loads that listing's editor (?ajax=1) into a
 *   row under it, and updates the URL so the page can be reloaded or shared
 * - Audit Listings runs in batches of 5 and swaps in each audited row as it
 *   comes back
 * - VC: Export All fetches every listing live in batches and downloads one CSV
 * - SC: Sync Products asks Amazon for the all-listings report, polls until
 *   it's ready, then reloads
 * Without JavaScript the forms post normally (the old no-JS fallbacks).
 *
 * Page data comes from <script type="application/json" id="listings-config">.
 */
const configEl = document.getElementById('listings-config');
if (configEl) {
    init(JSON.parse(configEl.textContent));
}

function format(template, values) {
    return Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, value), template);
}

function init(config) {
    const { rowAttr, t } = config;
    const prefix = rowAttr === 'asin' ? 'vc' : 'sc';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const tableWrap = document.getElementById(`${prefix}-listings-table`);
    const table = tableWrap?.querySelector('table');
    const tbody = table?.tBodies[0];
    if (!tbody) return;

    // The open editor keeps to the visible width of the table's scroll area (app.css).
    const trackWidth = () => tableWrap.style.setProperty('--listings-wrap-width', `${tableWrap.clientWidth}px`);
    trackWidth();
    new ResizeObserver(trackWidth).observe(tableWrap);

    function post(url, params = {}) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params),
        });
    }

    // ---- Search, filters and sort ----

    // A unit is a listing row plus its open editor row, if any.
    function units() {
        return Array.from(tbody.querySelectorAll(`tr[data-${rowAttr}]`)).map((row) => {
            const next = row.nextElementSibling;
            return { row, editor: next?.id === 'editor-row' ? next : null };
        });
    }

    const searchInput = document.getElementById(`${prefix}-search`);
    const filterButtons = Array.from(document.querySelectorAll('[data-filter-group] [data-filter]'));
    const rowsStatus = document.querySelector('[data-rows-status]');
    let activeFilter = 'all';

    function applyFilters() {
        const term = (searchInput?.value ?? '').trim().toLowerCase();
        const all = units();
        let shown = 0;
        all.forEach(({ row, editor }) => {
            const flags = (row.dataset.filters ?? '').split(' ');
            const visible = (activeFilter === 'all' || flags.includes(activeFilter)) && (!term || (row.dataset.search ?? '').includes(term));
            row.hidden = !visible;
            if (editor) editor.hidden = !visible;
            if (visible) shown++;
        });
        if (rowsStatus) {
            rowsStatus.textContent = shown === all.length ? '' : format(t.rowsShown, { shown, total: all.length });
        }
    }

    searchInput?.addEventListener('input', applyFilters);
    filterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activeFilter = button.dataset.filter;
            filterButtons.forEach((other) => other.setAttribute('aria-pressed', String(other === button)));
            applyFilters();
        });
    });

    // A cell's data-sort-value wins (for "3 hours ago", "2 mismatched", a
    // symbol); otherwise its text, numerically when both sides are numbers.
    function sortValue(cell) {
        if (!cell) return '';
        return cell.dataset.sortValue ?? cell.textContent.trim();
    }

    function compare(a, b) {
        const number = /^-?\d+(\.\d+)?$/;
        if (number.test(a) && number.test(b)) return parseFloat(a) - parseFloat(b);
        return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    }

    table.querySelectorAll('thead th[data-sort]').forEach((th) => {
        th.querySelector('.sort-btn')?.addEventListener('click', () => {
            const index = th.cellIndex;
            const ascending = th.getAttribute('aria-sort') !== 'ascending';
            table.querySelectorAll('thead th[aria-sort]').forEach((other) => other.removeAttribute('aria-sort'));
            th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

            const sorted = units().sort((u1, u2) => compare(sortValue(u1.row.cells[index]), sortValue(u2.row.cells[index])) * (ascending ? 1 : -1));
            sorted.forEach(({ row, editor }) => {
                tbody.appendChild(row);
                if (editor) tbody.appendChild(editor);
            });
        });
    });

    // ---- The editor row ----

    function setExpanded(row, expanded) {
        row.classList.toggle('is-selected', expanded);
        row.querySelectorAll('.listing-row-link[aria-expanded]').forEach((link) => link.setAttribute('aria-expanded', String(expanded)));
    }

    function closeEditor() {
        document.getElementById('editor-row')?.remove();
        tbody.querySelectorAll(`tr[data-${rowAttr}].is-selected`).forEach((row) => setExpanded(row, false));
    }

    // Just below the sticky header, and back to the left edge, since the
    // arrow that was clicked is usually the far-right column.
    function scrollToRow(row) {
        const header = table.tHead?.getBoundingClientRect().height ?? 0;
        const top = window.scrollY + row.getBoundingClientRect().top - header - 8;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        tableWrap.scrollLeft = 0;
    }

    async function openEditor(row, url) {
        closeEditor();
        setExpanded(row, true);

        const editorRow = document.createElement('tr');
        editorRow.id = 'editor-row';
        editorRow.className = 'editor-row';
        const cell = document.createElement('td');
        cell.colSpan = 100;
        const content = document.createElement('div');
        content.id = 'expanded-content';
        content.setAttribute('aria-busy', 'true');
        const loading = document.createElement('p');
        loading.className = 'muted';
        loading.textContent = t.loading;
        content.append(loading);
        cell.append(content);
        editorRow.append(cell);
        row.after(editorRow);

        try {
            const fragmentUrl = new URL(url);
            fragmentUrl.searchParams.set('ajax', '1');
            fragmentUrl.hash = '';
            const response = await fetch(fragmentUrl, { headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error(String(response.status));
            content.innerHTML = await response.text();
        } catch {
            loading.textContent = t.loadFailed;
        } finally {
            content.removeAttribute('aria-busy');
        }
        scrollToRow(row);
    }

    tbody.addEventListener('click', (event) => {
        const link = event.target.closest('.listing-row-link');
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) return;
        event.preventDefault();

        const row = link.closest(`tr[data-${rowAttr}]`);
        if (row.classList.contains('is-selected')) {
            history.pushState({}, '', location.pathname);
            closeEditor();
            return;
        }
        const url = new URL(link.href);
        history.pushState({}, '', url.pathname + url.search + url.hash);
        openEditor(row, url);
    });

    // ---- Audit Listings ----

    const auditForm = document.getElementById(`${prefix}-audit-form`);
    const auditButton = document.getElementById(`${prefix}-audit-btn`);
    const auditProgress = document.getElementById(`${prefix}-audit-progress`);
    const auditBar = document.getElementById(`${prefix}-audit-progress-bar`);
    const auditCount = document.getElementById(`${prefix}-audit-progress-count`);
    const lastFullAudit = document.getElementById(`${prefix}-last-full-audit`);

    // The server doesn't know which row is open, so an open row keeps that state.
    function swapRow(id, html) {
        const existing = document.getElementById(`row-${id}`);
        if (!existing) return;
        const holder = document.createElement('tbody');
        holder.innerHTML = html.trim();
        const fresh = holder.firstElementChild;
        if (!fresh) return;
        setExpanded(fresh, existing.classList.contains('is-selected'));
        fresh.hidden = existing.hidden;
        existing.replaceWith(fresh);
    }

    function showProgress(bar, count, done, total) {
        const shown = Math.min(done, total);
        bar.value = Math.round((shown / Math.max(1, total)) * 100);
        count.textContent = `${shown} / ${total}`;
    }

    auditForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        auditButton.disabled = true;
        auditButton.setAttribute('aria-busy', 'true');
        auditProgress.hidden = false;
        auditBar.value = 0;
        auditCount.textContent = '';

        try {
            let offset = 0;
            let done = false;
            while (!done) {
                const response = await post(config.auditBatchUrl, { offset, batch: 5 });
                if (!response.ok) throw new Error(String(response.status));
                const data = await response.json();
                offset = data.nextOffset;
                done = data.done || data.processed === 0;
                (data.rows ?? []).forEach((row) => swapRow(row.id, row.html));
                showProgress(auditBar, auditCount, offset, data.total);
                if (done && data.lastFullAudit && lastFullAudit) {
                    lastFullAudit.textContent = format(t.lastFullAudit, { time: data.lastFullAudit });
                }
            }
            applyFilters();
        } catch {
            window.toast?.(t.auditFailed, 'bad');
        } finally {
            auditProgress.hidden = true;
            auditButton.disabled = false;
            auditButton.removeAttribute('aria-busy');
        }
    });

    // ---- VC: Export All ----

    const exportButton = document.getElementById('vc-export-btn');
    if (exportButton && config.exportBatchUrl) {
        const exportProgress = document.getElementById('vc-export-progress');
        const exportBar = document.getElementById('vc-export-progress-bar');
        const exportCount = document.getElementById('vc-export-progress-count');

        const csvCell = (value) => {
            const text = String(value ?? '');
            return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
        };

        exportButton.addEventListener('click', async () => {
            exportButton.disabled = true;
            exportButton.setAttribute('aria-busy', 'true');
            exportProgress.hidden = false;
            exportBar.value = 0;

            const rows = [];
            try {
                let offset = 0;
                let done = false;
                while (!done) {
                    const response = await post(config.exportBatchUrl, { offset, batch: 5 });
                    if (!response.ok) throw new Error(String(response.status));
                    const data = await response.json();
                    offset = data.nextOffset;
                    done = data.done || data.processed === 0;
                    rows.push(...(data.rows ?? []));
                    showProgress(exportBar, exportCount, offset, data.total);
                }

                const csv = [config.exportColumns, ...rows].map((row) => row.map(csvCell).join(',')).join('\r\n');
                const link = document.createElement('a');
                link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
                link.download = `vc-listings-export_${new Date().toISOString().slice(0, 10)}.csv`;
                document.body.append(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(link.href);
            } catch {
                window.toast?.(t.exportFailed, 'bad');
            } finally {
                exportProgress.hidden = true;
                exportButton.disabled = false;
                exportButton.removeAttribute('aria-busy');
            }
        });
    }

    // ---- SC: Sync Products ----

    const syncForm = document.getElementById('sc-sync-form');
    if (syncForm && config.syncStartUrl) {
        const syncButton = document.getElementById('sc-sync-btn');
        const syncProgress = document.getElementById('sc-sync-progress');
        const syncLabel = document.getElementById('sc-sync-progress-label');

        syncForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            syncButton.disabled = true;
            syncButton.setAttribute('aria-busy', 'true');
            syncProgress.hidden = false;
            syncLabel.textContent = t.syncRequesting;

            try {
                const start = await (await post(config.syncStartUrl)).json();
                if (start.status === 'failed') throw new Error(start.message);
                syncLabel.textContent = t.syncWaiting;

                // A report has no progress of its own, only "processing" until it's done.
                let result = null;
                while (!result) {
                    await new Promise((resolve) => setTimeout(resolve, 5000));
                    const poll = await (await post(config.syncPollUrl, { reportId: start.reportId })).json();
                    if (poll.status === 'failed') throw new Error(poll.message);
                    if (poll.status === 'done') result = poll;
                }
                syncLabel.textContent = format(t.synced, { count: result.count });
                location.reload();
            } catch (error) {
                syncProgress.hidden = true;
                syncButton.disabled = false;
                syncButton.removeAttribute('aria-busy');
                window.toast?.(format(t.syncFailed, { message: error.message || '' }), 'bad');
            }
        });
    }

    // A page loaded with a listing selected scrolls to it.
    const selected = tbody.querySelector(`tr[data-${rowAttr}].is-selected`);
    if (selected && document.getElementById('editor-row')) {
        scrollToRow(selected);
    }
}
