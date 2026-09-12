/**
 * The shell's one job: post the form, and put the answer where it was.
 *
 * `GET /a/{slug}` never meters (SPEC §7.1, 2026-09-11). A reader the site
 * could charge is sent a shell — the lede and a form posting to the same URL —
 * rendered without a chain read, so it arrives at once. This posts that form
 * straight away with `X-Fragment: 1`, and the server answers with the article
 * and the inspector, both rendered by the request that did the metering.
 * They replace the shell's two together, so nothing left on screen predates
 * the charge.
 *
 * Without JavaScript the form is a button and the POST answers with a whole
 * page. Everything here is an upgrade of that, not a requirement for it.
 *
 * ---- two lines, the second earned ----
 *
 * The server writes both. The first shows as the POST goes out. The second
 * shows only if the answer has not arrived by `data-after-ms` — the wait is
 * longer than usual — so an ordinary charge never sees it, and a slow one gets
 * something new to read instead of one line going stale. Neither is a report;
 * the report is the page that replaces this one. This file has no opinion
 * about their contents.
 *
 * ---- prerendering ----
 *
 * A browser may render this page before the reader has chosen it — Chrome's
 * Speculation Rules prerender *runs scripts* — and posting from a page nobody
 * opened would charge for an article nobody read. So the POST waits until
 * `document.prerendering` is false. This is the whole of the site's prefetch
 * defence now: the GET that a prefetch makes is safe because it is a GET, and
 * the only way a speculative load reaches the POST is through here.
 *
 * ---- a failure, or a lost answer ----
 *
 * If the POST fails the reader gets the button back, with what went wrong.
 * Pressing it again is safe even when the first attempt was charged and only
 * its answer was lost: the grant was recorded before the body was rendered
 * (§7.3), so the second POST finds it and charges nothing.
 *
 * ---- returning to the page ----
 *
 * Back can restore the shell from the back/forward cache exactly as it was
 * left, mid-post, with lines about a wait that is no longer happening.
 * If the shell is still here on a `pageshow` from the cache, it starts again.
 * Nothing is lost by that: a charge that landed left a grant behind it.
 */

const form = document.querySelector('form[data-read-on]');
const status = form && form.querySelector('[data-read-on-status]');
const now = form && form.querySelector('[data-read-on-now]');
const later = form && form.querySelector('[data-read-on-later]');
const failed = form && form.querySelector('[data-read-on-failed]');
const manual = form && form.querySelector('[data-read-on-manual]');
const button = form && form.querySelector('button[type="submit"]');

// When the second line appears. The server writes it from
// `metering.long_wait_ms`, which is measured rather than guessed — see the
// config for the numbers. Past that, the wait is longer than usual.
const afterMs = Number(later?.dataset.afterMs) || 7000;
let laterTimer = null;

function quiet() {
    clearTimeout(laterTimer);
    laterTimer = null;
    if (later) later.hidden = true;
}

function ready() {
    quiet();
    delete form.dataset.sent;
    if (button) button.disabled = false;
    if (manual) manual.hidden = false;
    if (status) status.hidden = true;
    if (failed) {
        failed.hidden = true;
        failed.textContent = '';
    }
}

/**
 * Scripts inserted as markup do not run, and the answer can carry two: the
 * advance control's and, on the `set_meter` screens, the wallet's. A fresh
 * element with the same attributes does run.
 */
function activate(root) {
    for (const inert of root.querySelectorAll('script')) {
        const live = document.createElement('script');
        for (const { name, value } of inert.attributes) live.setAttribute(name, value);
        live.textContent = inert.textContent;
        inert.replaceWith(live);
    }
}

async function send() {
    if (form.dataset.sent === '1') return;
    ready();
    form.dataset.sent = '1';

    if (button) button.disabled = true;
    if (manual) manual.hidden = true;
    if (now) now.hidden = false;
    if (status) status.hidden = false;
    if (later) laterTimer = setTimeout(() => { later.hidden = false; }, afterMs);

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { 'X-Fragment': '1' },
        });
        if (!response.ok) throw new Error(`the site answered ${response.status}`);

        const answer = document.createElement('template');
        answer.innerHTML = await response.text();
        const article = answer.content.querySelector('article.piece');
        const inspector = answer.content.querySelector('.inspector-body');
        if (!article) throw new Error('the answer had no article in it');

        quiet();
        form.closest('article.piece').replaceWith(article);
        activate(article);

        const here = document.querySelector('details.inspector .inspector-body');
        if (inspector && here) {
            here.replaceWith(inspector);
            // `assets/inspector.js` finds its rows when the panel is opened;
            // this tells it the panel's contents changed underneath it, in
            // case the reader opened it while the POST was out.
            document.dispatchEvent(new CustomEvent('newsprint:inspector'));
        }
    } catch (error) {
        // The two lines go and the button comes back, with what went wrong
        // in place of them. Composed here rather than by the server because
        // the reason is only known here.
        ready();
        if (status && failed) {
            if (now) now.hidden = true;
            failed.textContent = `That did not finish: ${error.message}. Read on to try again.`;
            failed.hidden = false;
            status.hidden = false;
        }
    }
}

if (form) {
    ready();

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        send();
    });

    if (document.prerendering) {
        document.addEventListener('prerenderingchange', send, { once: true });
    } else {
        send();
    }

    window.addEventListener('pageshow', (event) => {
        if (event.persisted && form.isConnected) {
            ready();
            send();
        }
    });
}
