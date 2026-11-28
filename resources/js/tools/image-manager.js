/*
 * VC Image Manager, ported from the old page's inline script:
 * - clicking a title or arrow loads that product's 9-slot image grid
 *   (?ajax=1) into a row under it, and updates the URL
 * - Audit Images runs in batches of 5 and swaps in each audited row
 * Added in the port:
 * - search and column sort, as on the Listings Editors
 * - Remove asks for confirmation first (without JavaScript it posts as before)
 * - an upload or removal button is disabled once sent, so a double click
 *   can't send it twice
 *
 * Page data comes from <script type="application/json" id="image-manager-config">.
 */
const configEl = document.getElementById('image-manager-config');
if (configEl) {
    init(JSON.parse(configEl.textContent));
}

function format(template, values) {
    return Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, value), template);
}

function init(config) {
    const { t } = config;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const tableWrap = document.getElementById('img-products-table');
    const table = tableWrap?.querySelector('table');
    const tbody = table?.tBodies[0];
    if (!tbody) return;

    // The open grid keeps to the visible width of the table's scroll area (app.css).
    const trackWidth = () => tableWrap.style.setProperty('--listings-wrap-width', `${tableWrap.clientWidth}px`);
    trackWidth();
    new ResizeObserver(trackWidth).observe(tableWrap);

    // A unit is a product row plus its open grid row, if any.
    function units() {
        return Array.from(tbody.querySelectorAll('tr[data-asin]')).map((row) => {
            const next = row.nextElementSibling;
            return { row, grid: next?.id === 'editor-row' ? next : null };
        });
    }

    // ---- Search and sort ----

    const searchInput = document.getElementById('img-search');
    const rowsStatus = document.querySelector('[data-rows-status]');

    function applySearch() {
        const term = (searchInput?.value ?? '').trim().toLowerCase();
        const all = units();
        let shown = 0;
        all.forEach(({ row, grid }) => {
            const visible = !term || (row.dataset.search ?? '').includes(term);
            row.hidden = !visible;
            if (grid) grid.hidden = !visible;
            if (visible) shown++;
        });
        if (rowsStatus) rowsStatus.textContent = shown === all.length ? '' : format(t.rowsShown, { shown, total: all.length });
    }
    searchInput?.addEventListener('input', applySearch);

    const sortValue = (cell) => (cell ? (cell.dataset.sortValue ?? cell.textContent.trim()) : '');
    const compare = (a, b) => {
        const number = /^-?\d+(\.\d+)?$/;
        if (number.test(a) && number.test(b)) return parseFloat(a) - parseFloat(b);
        return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    };

    table.querySelectorAll('thead th[data-sort]').forEach((th) => {
        th.querySelector('.sort-btn')?.addEventListener('click', () => {
            const index = th.cellIndex;
            const ascending = th.getAttribute('aria-sort') !== 'ascending';
            table.querySelectorAll('thead th[aria-sort]').forEach((other) => other.removeAttribute('aria-sort'));
            th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
            units()
                .sort((u1, u2) => compare(sortValue(u1.row.cells[index]), sortValue(u2.row.cells[index])) * (ascending ? 1 : -1))
                .forEach(({ row, grid }) => {
                    tbody.appendChild(row);
                    if (grid) tbody.appendChild(grid);
                });
        });
    });

    // ---- The image grid row ----

    function setExpanded(row, expanded) {
        row.classList.toggle('is-selected', expanded);
        row.querySelectorAll('.image-row-link[aria-expanded]').forEach((link) => link.setAttribute('aria-expanded', String(expanded)));
    }

    function closeGrid() {
        document.getElementById('editor-row')?.remove();
        tbody.querySelectorAll('tr[data-asin].is-selected').forEach((row) => setExpanded(row, false));
    }

    function scrollToRow(row) {
        const header = table.tHead?.getBoundingClientRect().height ?? 0;
        window.scrollTo({ top: Math.max(0, window.scrollY + row.getBoundingClientRect().top - header - 8), behavior: 'smooth' });
        tableWrap.scrollLeft = 0;
    }

    async function openGrid(row, url) {
        closeGrid();
        setExpanded(row, true);

        const gridRow = document.createElement('tr');
        gridRow.id = 'editor-row';
        gridRow.className = 'editor-row';
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
        gridRow.append(cell);
        row.after(gridRow);

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
        const link = event.target.closest('.image-row-link');
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) return;
        event.preventDefault();

        const row = link.closest('tr[data-asin]');
        if (row.classList.contains('is-selected')) {
            history.pushState({}, '', location.pathname);
            closeGrid();
            return;
        }
        const url = new URL(link.href);
        history.pushState({}, '', url.pathname + url.search + url.hash);
        openGrid(row, url);
    });

    // ---- Uploads and removals (forms inside the grid, delegated) ----

    const removeModal = document.getElementById('img-remove-modal');
    const removeTitle = document.getElementById('img-remove-modal-title');
    const removeConfirm = document.getElementById('img-remove-confirm');
    let pendingRemoval = null;

    function markSending(form) {
        const button = form.querySelector('button[type="submit"]');
        if (!button) return;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = t.uploading;
    }

    tbody.addEventListener('submit', (event) => {
        const form = event.target;
        if (form.classList.contains('image-remove-form') && form !== pendingRemoval) {
            event.preventDefault();
            pendingRemoval = form;
            removeTitle.textContent = format(t.removeTitle, { slot: form.dataset.slotLabel ?? '' });
            removeModal.showModal();
            return;
        }
        if (form.classList.contains('image-upload-form') || form.classList.contains('image-remove-form')) {
            markSending(form);
        }
    });

    removeConfirm?.addEventListener('click', () => {
        if (!pendingRemoval) return;
        removeModal.close();
        markSending(pendingRemoval);
        pendingRemoval.requestSubmit();
    });
    removeModal?.addEventListener('close', () => {
        // Cancelled: forget the form, unless it is being sent.
        if (pendingRemoval && !pendingRemoval.querySelector('button[aria-busy]')) pendingRemoval = null;
    });

    // ---- Audit Images ----

    const auditForm = document.getElementById('img-audit-form');
    const auditButton = document.getElementById('img-audit-btn');
    const auditProgress = document.getElementById('img-audit-progress');
    const auditBar = document.getElementById('img-audit-progress-bar');
    const auditCount = document.getElementById('img-audit-progress-count');
    const lastFullAudit = document.getElementById('img-last-full-audit');

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
                const response = await fetch(config.auditBatchUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ offset, batch: 5 }),
                });
                if (!response.ok) throw new Error(String(response.status));
                const data = await response.json();
                offset = data.nextOffset;
                done = data.done || data.processed === 0;
                (data.rows ?? []).forEach((row) => swapRow(row.id, row.html));
                const shown = Math.min(offset, data.total);
                auditBar.value = Math.round((shown / Math.max(1, data.total)) * 100);
                auditCount.textContent = `${shown} / ${data.total}`;
                if (done && data.lastFullAudit && lastFullAudit) {
                    lastFullAudit.textContent = format(t.lastFullAudit, { time: data.lastFullAudit });
                }
            }
            applySearch();
        } catch {
            window.toast?.(t.auditFailed, 'bad');
        } finally {
            auditProgress.hidden = true;
            auditButton.disabled = false;
            auditButton.removeAttribute('aria-busy');
        }
    });

    const selected = tbody.querySelector('tr[data-asin].is-selected');
    if (selected && document.getElementById('editor-row')) {
        scrollToRow(selected);
    }
}
