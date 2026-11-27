/*
 * Page tours (decision 2026-11-29: the tools keep their "Tutorial" tours).
 * A page declares its steps next to its button with <x-tour-button>:
 *
 *   <button data-tour="tas-tour-steps">Tutorial</button>
 *   <script type="application/json" id="tas-tour-steps">[{"element": "#tas-controls", "intro": "..."}]</script>
 *
 * Steps whose element isn't on the page (an empty state, say) are skipped.
 * The tooltip is styled from the theme tokens in app.css.
 */
import introJs from 'intro.js';
import 'intro.js/minified/introjs.min.css';

export function startTour(steps) {
    const present = steps.filter((step) => !step.element || document.querySelector(step.element));
    if (!present.length) return;

    introJs()
        .setOptions({
            steps: present,
            showBullets: false,
            showProgress: true,
            exitOnOverlayClick: true,
            nextLabel: 'Next',
            prevLabel: 'Back',
            doneLabel: 'Done',
        })
        .start();
}

export function initTours() {
    window.startTour = startTour;

    document.querySelectorAll('[data-tour]').forEach((button) => {
        button.addEventListener('click', () => {
            const source = document.getElementById(button.dataset.tour);
            startTour(source ? JSON.parse(source.textContent) : []);
        });
    });
}
