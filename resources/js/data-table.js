/*
 * Behaviour for <x-data-table>: sort by column, filter rows by text, export
 * the visible rows as CSV, and copy the ASIN column. Works on any table
 * inside [data-table]; the server renders every row, so with JavaScript off
 * the table still shows everything.
 *
 * Markup it reads:
 *   th[data-sort]            sortable column; data-type="number" sorts numerically
 *   td[data-value]           the value to sort and export, when the text differs
 *   [data-table-filter]      text input that filters rows
 *   [data-table-csv]         button; data-filename sets the file name
 *   [data-table-copy]        button; copies the column named by data-column
 */

function cellValue(cell) {
    return (cell?.dataset.value ?? cell?.textContent ?? '').trim();
}

function rows(table) {
    return Array.from(table.tBodies[0]?.rows ?? []);
}

function sortBy(table, th) {
    const index = th.cellIndex;
    const numeric = th.dataset.type === 'number';
    const ascending = th.getAttribute('aria-sort') !== 'ascending';

    table.querySelectorAll('th[aria-sort]').forEach((other) => other.removeAttribute('aria-sort'));
    th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');

    const sorted = rows(table).sort((a, b) => {
        const x = cellValue(a.cells[index]);
        const y = cellValue(b.cells[index]);
        const result = numeric
            ? (parseFloat(x.replace(/[^0-9.-]/g, '')) || 0) - (parseFloat(y.replace(/[^0-9.-]/g, '')) || 0)
            : x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });

        return ascending ? result : -result;
    });
    sorted.forEach((row) => table.tBodies[0].appendChild(row));
}

function filter(table, text, status) {
    const needle = text.trim().toLowerCase();
    let shown = 0;
    rows(table).forEach((row) => {
        const match = needle === '' || row.textContent.toLowerCase().includes(needle);
        row.hidden = !match;
        if (match) shown++;
    });
    if (status) status.textContent = needle === '' ? '' : `${shown} of ${rows(table).length} rows`;
}

function csvCell(value) {
    return /[",\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
}

export function tableToCsv(table) {
    const head = Array.from(table.tHead?.rows[0]?.cells ?? []).map((th) => csvCell(th.textContent.trim()));
    const body = rows(table)
        .filter((row) => !row.hidden)
        .map((row) => Array.from(row.cells).map((cell) => csvCell(cellValue(cell))).join(','));

    return [head.join(','), ...body].join('\n');
}

function download(filename, text) {
    const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    const link = Object.assign(document.createElement('a'), { href: url, download: filename });
    link.click();
    URL.revokeObjectURL(url);
}

function columnIndex(table, name) {
    return Array.from(table.tHead?.rows[0]?.cells ?? []).findIndex((th) => th.dataset.column === name);
}

export function initDataTables(root = document) {
    root.querySelectorAll('[data-table]').forEach((wrapper) => {
        const table = wrapper.querySelector('table');
        if (!table) return;

        table.querySelectorAll('th[data-sort]').forEach((th) => {
            th.querySelector('.sort-btn')?.addEventListener('click', () => sortBy(table, th));
        });

        const status = wrapper.querySelector('[data-table-status]');
        wrapper.querySelector('[data-table-filter]')?.addEventListener('input', (event) => filter(table, event.target.value, status));

        wrapper.querySelector('[data-table-csv]')?.addEventListener('click', (event) => {
            download(event.currentTarget.dataset.filename || 'export.csv', tableToCsv(table));
        });

        wrapper.querySelectorAll('[data-table-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const index = columnIndex(table, button.dataset.column);
                if (index < 0) return;
                const values = rows(table).filter((row) => !row.hidden).map((row) => cellValue(row.cells[index])).filter(Boolean);
                await navigator.clipboard?.writeText([...new Set(values)].join('\n'));
                window.toast?.(`Copied ${new Set(values).size} ${button.dataset.noun || 'values'}`, 'good');
            });
        });
    });
}
