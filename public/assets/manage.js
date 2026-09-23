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
 * **The revoke is conditional, and that is the repair.** SPL `revoke` clears
 * whatever delegate is set, not this site's in particular, and a token account
 * holds one. A reader who has since authorized another site would lose that
 * site's permission by closing here — a site this one has no business
 * touching. So when the delegate is not this site's contract, the close sends
 * `close_contract` alone. The server answers the question, from the account,
 * in `/meter/close/prepare`.
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
    // program requires and the reason `core::tx` pairs them at all — but only
    // when the delegate to withdraw is this site's. Otherwise the close goes
    // alone and the other site's approval is left where the reader put it.
    const ours = prep.delegateIsContract !== false;
    const instructions = ours
        ? pay.closeAndRevoke(prep.payerTokenAccount, prep.payer, prep.site)
        : pay.closeContract(prep.site, prep.payer);

    if (!ours) {
        say('Closing without revoking: the delegate on your token account belongs to another site.', 'note');
    }

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
 * What the token account's delegate says after the close, read back rather
 * than asserted.
 *
 * A surviving delegate is an alarm when this site was the one that set it, and
 * is the correct outcome when another site's approval is what is there: the
 * close deliberately left it alone, and reporting it as STILL SET would accuse
 * this site of failing to do something it declined to do on purpose.
 */
function delegateRow(result) {
    if (!result.delegate) return 'none — read back from your token account';
    if (result.delegateIsContract === false) {
        return 'another site\'s, left as it was — ' + result.delegate;
    }
    return 'STILL SET: ' + result.delegate;
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
        ['delegate', delegateRow(result)],
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
