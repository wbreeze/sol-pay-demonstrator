/**
 * Two small jobs in the inspector: copying an address, and reading the event.
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
 * The panel is rendered whole by the server; the only thing missing is the
 * decoded `Metered` / `Renewed` / `Closed` event, which needs `getTransaction`
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

const panel = document.querySelector('details.inspector');
const row = document.querySelector('[data-event-for]');
const slot = row?.querySelector('[data-event-slot]');

if (panel && row && slot) {
    /** Once, not once per toggle: the answer cannot change for a landed signature. */
    let asked = false;

    const read = async () => {
        if (asked) return;
        asked = true;
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
            asked = false;
            slot.textContent = `could not reach this site to read it: ${error.message}`;
        }
    };

    if (panel.open) read();
    panel.addEventListener('toggle', () => {
        if (panel.open) read();
    });
}
