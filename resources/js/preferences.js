/*
 * Theme and text size. Both live in plain cookies, not localStorage, so the
 * layouts render the right `data-theme` and `data-font-size` on <html> in the
 * first response, with no flash (the old app did this for font size only).
 * Step 3b adds the theme to the user record; the cookie stays the fallback.
 *
 * Changing the theme dispatches `theme-changed` on document, as the old app
 * did, so charts can recolour. `detail.theme` is the theme name and
 * `detail.scheme` is 'light' or 'dark'.
 */

export const THEMES = ['midnight', 'graphite', 'daylight', 'evergreen'];
export const FONT_SIZES = ['normal', 'large'];

const ONE_YEAR = 60 * 60 * 24 * 365;

function setCookie(name, value) {
    document.cookie = `${name}=${value};path=/;max-age=${ONE_YEAR};SameSite=Lax`;
}

export function currentScheme() {
    return getComputedStyle(document.documentElement).colorScheme.includes('light') ? 'light' : 'dark';
}

export function setTheme(theme) {
    if (!THEMES.includes(theme)) return;
    document.documentElement.dataset.theme = theme;
    setCookie('theme', theme);

    document.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.themeOption === theme));
    });
    document.querySelectorAll('[data-theme-name]').forEach((el) => {
        el.textContent = document.querySelector(`[data-theme-option="${theme}"]`)?.title ?? theme;
    });

    document.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme, scheme: currentScheme() } }));
}

export function setFontSize(size) {
    if (!FONT_SIZES.includes(size)) return;
    document.documentElement.dataset.fontSize = size;
    setCookie('font_size', size);

    document.querySelectorAll('[data-font-size-option]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.fontSizeOption === size));
    });
}

export function initPreferences() {
    document.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.addEventListener('click', () => setTheme(button.dataset.themeOption));
    });
    document.querySelectorAll('[data-font-size-option]').forEach((button) => {
        button.addEventListener('click', () => setFontSize(button.dataset.fontSizeOption));
    });

    // Re-saving the server-rendered values refreshes the cookies' expiry.
    setCookie('theme', document.documentElement.dataset.theme || THEMES[0]);
    setCookie('font_size', document.documentElement.dataset.fontSize || FONT_SIZES[0]);
}
