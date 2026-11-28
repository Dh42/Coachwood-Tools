/*
 * BioSteel Team Sponsorship page. Ported from the old page's inline script;
 * the behaviour is the same:
 * - the search posts the email and swaps in the results, with no page reload
 * - "Create Order" (delegated, so it works on results added by a search)
 *   opens the dialog with the team's bottle count, and notes the pucks for
 *   hockey teams
 * - submitting creates the draft order, shows the result, then re-runs the
 *   search so the team shows as sent
 * While writes are off the server answers with what the order would have
 * contained; that is shown as a warning, not an error.
 *
 * Page data comes from <script type="application/json" id="ts-config">.
 */
const configEl = document.getElementById('ts-config');
if (configEl) {
    init(JSON.parse(configEl.textContent));
}

function init({ searchUrl, orderUrl, email, t }) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const searchForm = document.getElementById('searchForm');
    const emailInput = document.getElementById('email');
    const searchError = document.getElementById('searchError');
    const results = document.getElementById('sponsorshipResults');
    const searchSubmit = document.getElementById('searchSubmit');
    let lastSearchedEmail = email;

    function showSearchError(message) {
        searchError.textContent = message;
        searchError.hidden = false;
        emailInput.setAttribute('aria-invalid', 'true');
    }

    async function performSearch(address) {
        searchSubmit.disabled = true;
        searchSubmit.setAttribute('aria-busy', 'true');
        searchError.hidden = true;
        emailInput.removeAttribute('aria-invalid');

        try {
            const response = await fetch(searchUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ email: address }),
            });
            if (response.status === 422) {
                const data = await response.json();
                showSearchError(data.errors?.email?.[0] || t.invalidEmail);
                return;
            }
            if (!response.ok) {
                showSearchError(t.failed);
                return;
            }
            results.innerHTML = await response.text();
            lastSearchedEmail = address;
        } catch (err) {
            showSearchError(err.message);
        } finally {
            searchSubmit.disabled = false;
            searchSubmit.removeAttribute('aria-busy');
        }
    }

    searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        performSearch(emailInput.value.trim());
    });

    // The order dialog.
    const modal = document.getElementById('ts-order-modal');
    const form = document.getElementById('createOrderForm');
    const teamTitle = document.getElementById('createOrderTeamName');
    const teamInput = document.getElementById('createOrderTeamNameInput');
    const sportInput = document.getElementById('createOrderSportInput');
    const puckNote = document.getElementById('createOrderPuckNote');
    const quantity = document.getElementById('createOrderQuantity');
    const message = document.getElementById('createOrderMessage');
    const submit = document.getElementById('createOrderSubmit');

    function showMessage(tone, build) {
        message.replaceChildren();
        message.className = 'notice';
        message.style.setProperty('--tone', `var(--${tone})`);
        message.setAttribute('role', tone === 'bad' ? 'alert' : 'status');
        const body = document.createElement('div');
        build(body);
        message.append(body);
        message.hidden = false;
    }

    results.addEventListener('click', (e) => {
        const button = e.target.closest('.btn-create-order');
        if (!button) return;
        const sport = button.dataset.sport || '';
        teamTitle.textContent = button.dataset.teamName;
        teamInput.value = button.dataset.teamName;
        sportInput.value = sport;
        quantity.value = button.dataset.bottleCount;
        puckNote.hidden = sport.toUpperCase() !== 'HOCKEY';
        message.hidden = true;
        submit.disabled = false;
        modal.showModal();
        quantity.focus();
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        submit.disabled = true;
        message.hidden = true;
        let keepDisabled = false;

        try {
            const response = await fetch(orderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({
                    email: lastSearchedEmail,
                    teamName: teamInput.value,
                    quantity: parseInt(quantity.value, 10),
                    sport: sportInput.value || null,
                }),
            });
            const data = await response.json();

            if (data.success) {
                keepDisabled = true;
                showMessage('good', (body) => {
                    body.append(t.created + ' ');
                    const link = document.createElement('a');
                    link.href = data.adminUrl;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = data.orderName ?? data.orderId;
                    body.append(link, ' — ' + (data.emailSent ? t.emailed : t.notEmailed));
                });
                await performSearch(lastSearchedEmail);
                setTimeout(() => modal.close(), 1500);
            } else if (data.writesDisabled) {
                showMessage('warn', (body) => {
                    const strong = document.createElement('strong');
                    strong.textContent = data.error;
                    const list = document.createElement('ul');
                    (data.lineItems ?? []).forEach((item) => {
                        const li = document.createElement('li');
                        li.textContent = `${item.quantity} × ${item.label}`;
                        list.append(li);
                    });
                    body.append(strong, list);
                });
            } else {
                showMessage('bad', (body) => body.append(data.error || data.message || t.orderFailed));
            }
        } catch (err) {
            showMessage('bad', (body) => body.append(err.message));
        } finally {
            submit.disabled = keepDisabled;
        }
    });
}
