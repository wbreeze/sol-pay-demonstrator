/**
 * Four small jobs in the inspector: copying an address, reading the panel on
 * a page that deferred it, reading the event, and saying what the wallet
 * sent.
 *
 * ---- copying ----
 *
 * §9: "The full base58 is always one click away and always what gets copied —
 * a copy button never yields an alias." The address comes from the button's
 * own data attribute rather than from the cell's text, so no later change to
 * how the row is laid out can quietly make it copy the short name.
 *
 * Delegated from the document, because the panel is inside a `<details>` and
 * the rows exist whether or not it is open. One listener rather than one per
 * address.
 *
 * ---- reading the event ----
 *
 * Where the server rendered the panel, the only thing missing is the decoded
 * `Metered` / `Renewed` / `Closed` event, which needs `getTransaction`
 * and therefore a fourth RPC call on a request §12.4 budgets about three for.
 * §9 says the panel is collapsed by default, so that call is spent by readers
 * who actually open it and by nobody else.
 *
 * Everything here is deliberately small. The server composes the sentence —
 * amounts belong in both unit forms and `Units::fromBaseUnits` lives in PHP —
 * so this moves one string into one cell and has no opinion about its
 * contents. Without JavaScript the row keeps the sentence the server put
 * there, which stays true rather than becoming a spinner that never resolves.
 */

/* §9's copy rule, in eight lines. `navigator.clipboard` needs a secure
   context, and `http://localhost` is one — measured, not assumed. If it is
   refused anyway the button says so rather than failing silently, because a
   button that looks like it worked is worse than one that admits it did not. */
document.addEventListener('click', async (event) => {
    const button = event.target.closest?.('[data-copy-address]');
    if (!button) return;

    const said = (word) => {
        button.textContent = word;
        button.dataset.said = '';
        setTimeout(() => {
            button.textContent = 'copy';
            delete button.dataset.said;
        }, 1200);
    };

    try {
        await navigator.clipboard.writeText(button.dataset.copyAddress);
        said('copied');
    } catch {
        said('cannot copy');
    }
});

/* ---- reading the panel itself ----
 *
 * A page that needed nothing from the chain rendered no panel body, only the
 * paragraph carrying `data-panel-src`. Measured 2026-09-09: filling that body
 * on every page view cost one `getMultipleAccounts`, and on `/privacy` that
 * call was the entire page load — 0.9 s to display nothing from the chain.
 *
 * Same shape as the event read below, and the same argument: §9 says this
 * panel is collapsed by default, so the read belongs to the reader who opens
 * it. The paragraph is a real link, so this is an upgrade rather than a
 * requirement — with no JavaScript the link still goes to the panel. */
const panel = document.querySelector('details.inspector');

/* ---- found when asked for, not when the page loaded ----
 *
 * An article shell (`assets/read-on.js`, 2026-09-11) replaces this panel's
 * body with the one its POST rendered — the deferred paragraph goes, and a
 * last-transaction row may arrive that was not there at load. So neither the
 * paragraph nor the row is looked up once and kept: each open asks the
 * document what is there now, and a replaced body is a fresh start. The shell
 * says so with a `newsprint:inspector` event. */
let panelAsked = false;
let eventAsked = false;

const loadPanel = async () => {
    const deferred = panel.querySelector('[data-panel-src]');
    if (!deferred || panelAsked) return;
    panelAsked = true;
    const saying = deferred.textContent;
    deferred.textContent = 'reading the accounts…';

    try {
        const response = await fetch(deferred.dataset.panelSrc, {
            headers: { 'X-Fragment': '1' },
        });
        if (!response.ok) throw new Error(`the site answered ${response.status}`);
        const markup = await response.text();

        // A shell's answer may have replaced the body while this read was
        // out. It is the newer of the two, rendered by the request that
        // charged, so this one is dropped rather than put beside it.
        if (!deferred.isConnected) return;

        // `insertAdjacentHTML` and then remove the paragraph, rather than
        // replacing `innerHTML` on a parent: the parent holds the script
        // tag and the summary too, and rewriting it would tear down the
        // element whose toggle event is running.
        deferred.insertAdjacentHTML('afterend', markup);
        deferred.remove();
    } catch (error) {
        // Put the reader back where they started -- a link they can click
        // -- rather than leaving a spinner that never resolves. Lowering
        // the flag means the next open tries again, which is what a reader
        // reopening it is asking for.
        panelAsked = false;
        deferred.textContent = `${saying.trim()} (that read failed: ${error.message})`;
    }
};

/** Once per row, not once per toggle: the answer cannot change for a landed signature. */
const readEvent = async () => {
    const row = panel.querySelector('[data-event-for]');
    const slot = row?.querySelector('[data-event-slot]');
    if (!row || !slot || eventAsked) return;
    eventAsked = true;
    slot.textContent = 'reading it from the chain…';

    try {
        const response = await fetch(
            `/inspector/event/${encodeURIComponent(row.dataset.eventFor)}`,
            { headers: { Accept: 'application/json' } },
        );
        const body = await response.json();
        slot.textContent = typeof body.text === 'string'
            ? body.text
            : 'the answer was not in the shape this page expected';
    } catch (error) {
        // A transaction that has not landed yet is a "try again", so the
        // flag goes back down and the next open asks once more. A reader
        // who closes and reopens is asking again on purpose.
        eventAsked = false;
        slot.textContent = `could not reach this site to read it: ${error.message}`;
    }
};

/* ---- what the wallet sent ----
 *
 * A wallet submits its own setup, renewal or deposit, so no request to this
 * site knows that one has just gone. This browser knows: the reader pressed
 * *continue*, and `assets/key.js` left the kind in `sessionStorage` on the
 * way to this page. *Your wallet* carries a row for each kind in an inert
 * `<template>` element, written by the server with the panel's other words.
 * This moves the matching row into the table and writes nothing itself.
 *
 * **Read once and removed, so the row lasts one page.** A reload loses the
 * row, as a reload loses a charge's instructions. The kind is kept in memory
 * for the life of the page, because an article's answer replaces the panel's
 * body (`assets/swap.js`) and the row has to be put back into the new one.
 *
 * A prerendered page waits until it is shown. A page that is never shown
 * would otherwise take the word and say it to nobody. */
const SENT = 'newsprint:sent';
let sent = null;

const takeSent = () => {
    try {
        sent = sessionStorage.getItem(SENT);
        sessionStorage.removeItem(SENT);
    } catch {
        // No storage: nothing was left, and nothing is said.
    }
};

const showSent = () => {
    if (!sent || !panel) return;
    const kept = panel.querySelector('template[data-wallet-sent]');
    const table = kept?.parentElement?.querySelector('table');
    if (!table || table.querySelector('[data-sent]')) return;

    const row = [...kept.content.querySelectorAll('[data-sent]')]
        .find((candidate) => candidate.dataset.sent === sent);
    if (row) (table.tBodies[0] ?? table).append(row.cloneNode(true));
};

const opened = () => {
    if (!panel.open) return;
    // After the deferred read as well: that panel arrives with its own
    // `<template>` element, and the row belongs in it too.
    loadPanel().then(showSent);
    readEvent();
};

const arrived = () => {
    if (document.prerendering) return;
    takeSent();
    showSent();
};

if (panel) {
    arrived();
    document.addEventListener('prerenderingchange', arrived);
    opened();
    panel.addEventListener('toggle', opened);
    document.addEventListener('newsprint:inspector', () => {
        panelAsked = false;
        eventAsked = false;
        showSent();
        opened();
    });
}
