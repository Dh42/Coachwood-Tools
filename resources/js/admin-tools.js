/*
 * Admin > Tools reordering. Rows move by dragging the handle (SortableJS,
 * as in the old app, with its own pointer handling because native drag and
 * drop is unreliable for table rows) or with the Move up / Move down
 * buttons, which work from the keyboard. After either, the whole order is
 * sent to the reorder endpoint, as before. A failure puts the rows back.
 *
 * Loaded only on this page, so SortableJS stays out of the shared bundle.
 */
import Sortable from 'sortablejs';

function init(table) {
    const tbody = table.tBodies[0];
    const status = document.querySelector('[data-reorder-status]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const rows = () => Array.from(tbody.querySelectorAll('tr[data-tool-id]'));
    let saved = rows();

    async function save(movedRow) {
        const order = rows().map((row) => Number(row.dataset.toolId));
        try {
            const response = await fetch(table.dataset.reorderUrl, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ order }),
            });
            if (!response.ok) throw new Error(String(response.status));
            saved = rows();
            if (status && movedRow) status.textContent = `Moved to position ${order.indexOf(Number(movedRow.dataset.toolId)) + 1} of ${order.length}.`;
            window.toast?.('Order saved.', 'good');
        } catch {
            saved.forEach((row) => tbody.appendChild(row));
            window.toast?.('The new order could not be saved. Try again.', 'bad');
        }
    }

    Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 150,
        forceFallback: true,
        fallbackClass: 'sortable-fallback',
        onEnd: (event) => {
            if (event.oldIndex !== event.newIndex) save(event.item);
        },
    });

    tbody.addEventListener('click', (event) => {
        const button = event.target.closest('[data-move]');
        if (!button) return;
        const row = button.closest('tr');
        const sibling = button.dataset.move === 'up' ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling) return;
        if (button.dataset.move === 'up') sibling.before(row);
        else sibling.after(row);
        button.focus();
        save(row);
    });
}

document.querySelectorAll('[data-tools-table]').forEach(init);
