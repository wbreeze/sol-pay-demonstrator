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
 * that an advance is under way — because the outcome is not known yet, and the
 * page the redirect lands on reports it: charged, settled, refused or blocked.
 * The server composes the sentence; this has no opinion about its contents.
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

const ADVANCE_KEYS = ['advance', 'views', 'tx', 'settled'];

const form = document.querySelector('form[data-advance]');
const button = form && form.querySelector('button[type="submit"]');
const status = form && form.querySelector('[data-advance-status]');

function ready() {
    if (!form) return;
    delete form.dataset.sent;
    if (button) button.disabled = false;
    if (status) status.hidden = true;
}

if (form) {
    ready();

    form.addEventListener('submit', (event) => {
        // Belt to the disabled button's braces: a second submit by any other
        // route is refused here rather than sent as a second advance.
        if (form.dataset.sent === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.sent = '1';
        // Disabled inside the submit event, not on click, so the submission
        // that is already under way is not the one refused. The button has no
        // name, so leaving the form data does not change what is posted.
        if (button) button.disabled = true;
        if (status) status.hidden = false;
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) ready();
    });
}

const url = new URL(window.location.href);
if (ADVANCE_KEYS.some((key) => url.searchParams.has(key))) {
    for (const key of ADVANCE_KEYS) url.searchParams.delete(key);
    window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
}
