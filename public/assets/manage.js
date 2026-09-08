/**
 * `manage_meter`: renew, or close and revoke.
 *
 * Renewing is the same flow as opening — the server decides which by reading
 * the chain — so it goes through `openOrRenew` in `tx.js` and nothing here
 * knows the difference.
 *
 * Closing is its own path, and its own thing to get right. `close_and_revoke`
 * is two instructions: `close_contract`, which forgives whatever residue is
 * unpaid, and `revoke`, which withdraws the delegate's authority over the
 * reader's token account. What the wallet shows afterwards is the point of
 * claim 6 in §2 — leaving costs nothing and leaves nothing behind.
 *
 * The server's half of §10.4 runs after the chain confirms, and this page
 * **shows what it deleted** rather than asserting that it did.
 */

import { accountFor, context, openOrRenew, post, rememberedWallet, sign } from './tx.js';

const panel = document.querySelector('[data-manage]');
const ctx = context(panel);
const { say, guard } = ctx;

function walletOrFail() {
    const wallet = rememberedWallet(ctx);
    if (!wallet) {
        throw new Error('no wallet that speaks this chain has announced itself to this page. Reload, or connect again from an article.');
    }
    return wallet;
}

/* -- renew ----------------------------------------------------------------- */

const renew = panel && panel.querySelector('[data-renew]');
const limitField = panel && panel.querySelector('[data-limit]');

if (renew) {
    if (window.requestIdleCallback) window.requestIdleCallback(ctx.preload);
    else window.setTimeout(ctx.preload, 0);

    renew.addEventListener('click', () => guard(renew, async () => {
        const result = await openOrRenew(ctx, walletOrFail(), limitField.value.trim());
        if (result.pending) {
            say(result.message, 'note');
            return;
        }
        window.location.reload();
    }));
}

/* -- close and revoke ------------------------------------------------------ */

const close = panel && panel.querySelector('[data-close]');
const receipt = panel && panel.querySelector('[data-receipt]');
const controls = panel && panel.querySelector('[data-controls]');

async function runClose(wallet) {
    // Before the server round trip, so any wallet prompt is inside this click.
    say('Reconnecting to ' + wallet.name + '…');
    const account = await accountFor(wallet, ctx.payer);

    say('Preparing…');
    const [pay, kit] = await ctx.preload();

    const preparedAt = Date.now();
    const prep = await post('/meter/close/prepare');
    ctx.trace.prepare = prep;
    ctx.trace.timings.preparedAt = new Date(preparedAt).toISOString();

    if (prep.payer !== account.address) {
        throw new Error('this site is now identifying a different wallet; reload the page');
    }

    // `close_contract` then `revoke`, in that order, which is the order the
    // program requires and the reason `core::tx` pairs them at all.
    const instructions = pay.closeAndRevoke(prep.payerTokenAccount, prep.payer, prep.site);

    const signature = await sign(ctx, kit, wallet, account, prep, instructions, preparedAt);

    say('Sent. Waiting for the chain…');
    const result = await post('/meter/close/done', { signature });

    if (result.pending) {
        say(result.message, 'note');
        return;
    }

    show(result);
}

/**
 * The receipt.
 *
 * §10.4 says erasure should be "a `DELETE` a reader can watch happen", which
 * means the page does not reload into a shrug. It stays, and it reports the
 * rows that went — by count, because the contents are exactly what was just
 * deleted and reprinting them would be an odd way to honour the request.
 */
function show(result) {
    say('');
    if (controls) controls.hidden = true;
    if (!receipt) return;

    const erased = result.erased || {};
    const rows = [
        ['contract', 'closed, on chain'],
        // Read back from the token account after the close rather than
        // asserted — this is the line §2's claim 6 is actually about.
        ['delegate', result.delegate ? 'STILL SET: ' + result.delegate : 'none — read back from your token account'],
        ['session', (erased.sessions || 0) + ' row deleted — paying wallet forgotten'],
        ['view grants', (erased.grants || 0) + ' row(s) deleted'],
        ['faucet ledger', 'kept, for the reason on the privacy page'],
    ];

    const list = document.createElement('dl');
    list.className = 'receipt';
    for (const [term, detail] of rows) {
        const dt = document.createElement('dt');
        dt.textContent = term;
        const dd = document.createElement('dd');
        dd.textContent = detail;
        list.append(dt, dd);
    }

    const heading = document.createElement('h2');
    heading.textContent = 'Closed';

    const signature = document.createElement('p');
    signature.className = 'fine';
    signature.textContent = 'signature ' + result.signature;

    const back = document.createElement('p');
    const link = document.createElement('a');
    link.href = '/';
    link.textContent = 'Back to the paper';
    back.append(link);

    receipt.replaceChildren(heading, list, signature, back);
    receipt.hidden = false;
}

if (close) {
    close.addEventListener('click', () => guard(close, () => runClose(walletOrFail())));
}
