/*
 * Small shared behaviours: the sidebar toggle on narrow screens, modals
 * (native <dialog>), toasts, and copy buttons.
 *
 *   <button data-modal-open="confirm-delete">   opens <dialog id="confirm-delete">
 *   <button data-modal-close>                    closes its dialog
 *   window.toast('Saved', 'good')                tones: good, bad, warn, info
 *   <button data-copy="text" or data-copy-from="#id">
 */

const TOAST_ICONS = { good: 'M5 12l5 5 9-10', bad: 'M6 6l12 12M18 6L6 18', warn: 'M12 3l10 18H2zM12 10v4M12 17h.01', info: 'M12 8h.01M11 12h1v5h1' };

export function toast(message, tone = 'info') {
    const region = document.querySelector('[data-toasts]');
    if (!region) return;

    const el = document.createElement('div');
    el.className = `notice toast`;
    el.style.setProperty('--tone', `var(--${tone})`);
    el.setAttribute('role', tone === 'bad' ? 'alert' : 'status');

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', TOAST_ICONS[tone] ?? TOAST_ICONS.info);
    svg.appendChild(path);

    const text = document.createElement('span');
    text.textContent = message;
    el.append(svg, text);
    region.appendChild(el);

    const duration = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--duration-toast'), 10) || 4000;
    setTimeout(() => el.remove(), duration);
}

export function initUi() {
    window.toast = toast;

    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const sidebar = button.closest('.sidebar');
            const open = sidebar.classList.toggle('is-open');
            button.setAttribute('aria-expanded', String(open));
        });
    });

    document.querySelectorAll('[data-modal-open]').forEach((button) => {
        button.addEventListener('click', () => document.getElementById(button.dataset.modalOpen)?.showModal());
    });
    document.querySelectorAll('[data-modal-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });

    document.querySelectorAll('[data-copy], [data-copy-from]').forEach((button) => {
        button.addEventListener('click', async () => {
            const source = button.dataset.copyFrom ? document.querySelector(button.dataset.copyFrom) : null;
            const text = button.dataset.copy ?? source?.value ?? source?.textContent ?? '';
            await navigator.clipboard?.writeText(text);
            toast(button.dataset.copied || 'Copied', 'good');
        });
    });

    // Flash messages rendered by the server as <template data-toast="tone">.
    document.querySelectorAll('template[data-toast]').forEach((template) => {
        toast(template.content.textContent.trim(), template.dataset.toast);
        template.remove();
    });
}
