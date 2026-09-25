/**
 * Confirm afterward (SPEC §7.3, 2026-09-17).
 *
 * The charging POST now answers as soon as the endpoint has accepted the
 * charge, so the article arrives while the chain is still deciding. The strip
 * under it says so and shows no account figures, because any read taken then
 * would predate the charge. It also carries a `data-charge-pending` element
 * naming where to ask, and this sends that request at once: the server waits
 * for the confirmation there, off the request the reader was waiting on, and
 * answers with the article and the inspector as they stand afterwards.
 * `swap.js` puts them in place, as it does for the charge itself.
 *
 * Without JavaScript the strip offers a link back to the article, and
 * `GET /a/{slug}` asks the chain once on the way. Everything here is an upgrade of that.
 *
 * ---- once per strip, and not forever ----
 *
 * **Listened for from the document**, for `swap.js`'s reason: this module
 * arrives inside a swapped answer and runs once per document, and the strip it
 * serves can be replaced any number of times. Each strip is asked about once.
 * A page that keeps coming back pending — another tab holding the same grant,
 * say — stops after a few rounds and leaves the reload to the reader, rather
 * than asking the endpoint in a loop.
 *
 * ---- prerendering ----
 *
 * A granted page can be prerendered, and this POST charges nothing — but it
 * writes what became of the charge onto the grant, and a page nobody opened
 * has no business doing that. So it waits, like `read-on.js`.
 *
 * ---- returning to the page ----
 *
 * A page restored from the back/forward cache asks again, once.
 *
 * ---- a failure ----
 *
 * Nothing is lost: the reader has the article, and the grant is intact. The
 * line says what went wrong and that a reload shows where the charge stands.
 */

import { swap } from './swap.js';

const MAX_ROUNDS = 3;
let rounds = 0;

async function ask() {
    const pending = document.querySelector('[data-charge-pending]');
    if (!pending || pending.dataset.asked === '1' || rounds >= MAX_ROUNDS) return;
    pending.dataset.asked = '1';
    rounds += 1;

    try {
        await swap(pending.dataset.chargePending);
    } catch (error) {
        const failed = pending.querySelector('[data-charge-failed]');
        if (failed && failed.isConnected) {
            failed.textContent = `Could not check on the charge: ${error.message}. Open the article again to see where it stands.`;
            failed.hidden = false;
        }
    }
}

function start() {
    ask();
    document.addEventListener('newsprint:swapped', ask);

    // Back can restore a page from the cache still saying the charge is on
    // its way, with its one question already spent. It is a new look at the
    // page, so it gets a new question.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        const pending = document.querySelector('[data-charge-pending]');
        if (pending) delete pending.dataset.asked;
        rounds = 0;
        ask();
    });
}

if (document.prerendering) {
    document.addEventListener('prerenderingchange', start, { once: true });
} else {
    start();
}
