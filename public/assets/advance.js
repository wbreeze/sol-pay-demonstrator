/**
 * The seven-view advance (§7.4): say that the click registered.
 *
 * The advance is a form `POST` and a `303` back to the article, and the whole
 * of it waits on a validator — 3 to 11 seconds measured, with the old page on
 * screen and nothing to say the button was pressed. This file does three small
 * things about that and nothing else. The form, the redirect and the report on
 * the page it lands on are all server work and all work without it.
 *
 * ---- on submit ----
 *
 * **The button is disabled first, and that is the part that matters.** Each
 * `POST /meter/advance` is its own `advance()`; §7.2's payer lock queues a
 * second one behind the first but does not merge them, so a double click is
 * two advances and fourteen views charged. Then the sentence the server put in
 * the form is shown. It says only what is true the moment the form is sent —
 * that an advance is under way — because the outcome is not known yet. The
 * server composes the sentence; this has no opinion about its contents.
 *
 * ---- and the answer lands here (2026-09-11) ----
 *
 * The POST is sent with `X-Fragment: 1` and the answer — the article and the
 * inspector, rendered by the request that built the transaction — replaces
 * them both, the same way the article shell's does (`swap.js`).
 *
 * **This is what pays off §9's last debt.** A redirect can carry the advance's
 * signature in its query and cannot carry its instructions: they are built in
 * that request, and §10.4 leaves this site nowhere to keep them. So the page
 * after an advance showed no last-transaction section at all — nine advances,
 * nine redirects, no section, across three captures. Rebuilding them for
 * display was refused (a panel that rebuilds its own evidence agrees with
 * itself whatever was sent), reading them back with `getTransaction` was
 * refused (that is "as they landed" under a heading promising "as the builders
 * produced them"), and carrying them through the URL would make them
 * reader-supplied. Not reloading is the fourth way, and it costs nothing.
 *
 * Without JavaScript the form still posts and the server still redirects, so
 * that reader gets the outcome and the signature and no instruction list —
 * which is what everyone got until now.
 *
 * ---- on returning to the page ----
 *
 * The server never renders the button disabled or the sentence shown, so
 * either one on arrival is the browser's memory rather than the page. Back can
 * restore the article from the back/forward cache exactly as it was left —
 * mid-click, the button dead and the sentence claiming an advance in progress,
 * forever — and Firefox restores a control's `disabled` state even on an
 * ordinary reload. So both are reset when the module runs and again on a
 * `pageshow` that came from the cache.
 *
 * ---- shown once ----
 *
 * `meter-strip.php` says the advance's report is "shown once, from the
 * redirect, and not stored". The URL was what broke that: refresh, or back to
 * a page that was itself an advance result, re-requested
 * `?advance=metered&tx=…` and reported an old advance as if it had just
 * happened. Once the page has rendered the report, its query keys are removed
 * from the address with `replaceState`, so back, forward and refresh land on
 * the plain article — and the signature stops sitting in browser history. The
 * keys are the ones the `/meter/advance` route writes; `TemplateRenderTest`
 * holds the two lists together.
 */

import { swap } from './swap.js';

const ADVANCE_KEYS = ['advance', 'views', 'tx', 'settled'];

/**
 * **Listened for from the document, not bound to the form.** The answer to an
 * advance carries the next advance's form, and the module that would bind to
 * it cannot run twice in one document (see `swap.js`). A delegated listener
 * outlives every swap, and what it acts on is whatever form is on the page
 * when a reader presses the button.
 */
const parts = (form) => ({
    button: form.querySelector('button[type="submit"]'),
    status: form.querySelector('[data-advance-status]'),
    failed: form.querySelector('[data-advance-failed]'),
});

function ready(form) {
    const { button, status, failed } = parts(form);
    delete form.dataset.sent;
    if (button) button.disabled = false;
    if (status) status.hidden = true;
    if (failed) {
        failed.hidden = true;
        failed.textContent = '';
    }
}

function reset() {
    const form = document.querySelector('form[data-advance]');
    if (form) ready(form);
}

document.addEventListener('submit', async (event) => {
    const form = event.target.closest?.('form[data-advance]');
    if (!form) return;

    event.preventDefault();
    // Belt to the disabled button's braces: a second submit by any other
    // route is refused here rather than sent as a second advance.
    if (form.dataset.sent === '1') return;
    form.dataset.sent = '1';

    const { button, status, failed } = parts(form);
    if (button) button.disabled = true;
    if (status) status.hidden = false;

    try {
        await swap(form.action, new FormData(form));
    } catch (error) {
        // The button comes back with what went wrong. Pressing it again
        // sends a **second advance**, and that is the honest offer: unlike
        // the article's POST there is no grant to make a retry free, so
        // the line says so rather than inviting a click that might charge
        // seven views twice. Whether the first one landed is a question
        // the meter answers — reload and read it.
        ready(form);
        if (failed) {
            failed.textContent = `That did not finish: ${error.message}. `
                + 'It may still have gone through — reload and read the meter before advancing again.';
            failed.hidden = false;
        }
    }
});

reset();
window.addEventListener('pageshow', (event) => {
    if (event.persisted) reset();
});

const url = new URL(window.location.href);
if (ADVANCE_KEYS.some((key) => url.searchParams.has(key))) {
    for (const key of ADVANCE_KEYS) url.searchParams.delete(key);
    window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
}
