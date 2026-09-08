/**
 * Everything two panels need to hand a transaction to a wallet.
 *
 * Extracted from `meter.js` when `manage_meter` arrived, because the second
 * copy of "load kit, reconnect the account, compile, send, and write down what
 * went wrong" would have been the copy that drifted. The parts that differ
 * between the two screens — which instructions, what the screen says — stay in
 * the screens.
 *
 * Nothing here is loaded until a reader clicks something. A reader who never
 * authorizes never downloads kit.
 */

import { CONNECT, signAndSend, usable, wasDeclined } from './wallet.js';

/* -- the shared bits of a panel -------------------------------------------- */

/**
 * Bind the shared behaviour to one panel element.
 *
 * The panel carries what the browser needs as data attributes, all of them
 * decided by the server: which chain, which wallet this site identified, and
 * the two program addresses. The browser is told; it does not choose.
 */
export function context(panel) {
    const status = panel && panel.querySelector('[data-status], [data-meter-status]');

    // For the console, on a machine Claude cannot reach.
    const trace = { prepare: null, instructions: null, wire: null, timings: {}, error: null };
    window.__newsprint = trace;

    const say = (message, kind = 'note') => {
        if (!status) return;
        status.textContent = message;
        status.dataset.kind = kind;
        status.hidden = message === '';
    };

    const ctx = {
        panel,
        status,
        trace,
        say,
        chain: (panel && panel.dataset.chain) || null,
        payer: (panel && panel.dataset.payer) || null,
        program: (panel && panel.dataset.program) || null,
        tokenProgram: (panel && panel.dataset.tokenProgram) || null,
    };

    /**
     * Start both downloads as soon as the panel is on screen.
     *
     * 221 KB of kit and 125 KB of wasm were once fetched *after* the
     * blockhash, which put them inside the window §6.3 warns about: a
     * blockhash has to survive the reader going into their wallet, and every
     * byte downloaded between the two spends part of its life. Download first,
     * fetch the blockhash second.
     *
     * Not a wallet call, so it needs no user gesture.
     */
    let libraries = null;
    ctx.preload = () => {
        if (libraries === null && ctx.program) {
            libraries = Promise.all([loadPay(ctx.program, ctx.tokenProgram), loadKit()]);
            // Swallowed here; the click reports it when it actually needs them.
            libraries.catch(() => {});
        }
        return libraries;
    };

    /** One place where things go wrong, for every button on the panel. */
    ctx.guard = async (button, work) => {
        button.disabled = true;
        try {
            await work();
        } catch (error) {
            trace.error = error;
            console.error('[newsprint]', error);
            button.disabled = false;

            if (wasDeclined(error)) {
                say('You dismissed the wallet. Nothing was signed and nothing was charged.');
                return;
            }

            say(String((error && error.message) || error), 'error');
            await report(ctx, error);
        }
    };

    return ctx;
}

/* -- which wallet ---------------------------------------------------------- */

const REMEMBERED = 'newsprint.wallet';

/**
 * Which wallet to talk to, on any step after the first.
 *
 * Remembered per tab, not per reader: it is the *name of a browser*
 * *extension*, it never reaches the server, and it is gone when the tab
 * closes. The site's record of you is still one address and a session id.
 *
 * The fallback when nothing is remembered is the first usable wallet, which
 * is right because `usable` already filters to wallets that speak this chain
 * and offer what this site needs.
 */
export function rememberedWallet(ctx) {
    let name = null;
    try {
        name = window.sessionStorage.getItem(REMEMBERED);
    } catch (e) {
        // Private windows and blocked site data both throw here; the fallback
        // is asking again, which costs one click.
    }
    const ready = usable(ctx.chain);
    if (!name) return ready[0] || null;
    return ready.find((w) => w.name === name) || ready[0] || null;
}

export function rememberWallet(wallet) {
    try {
        window.sessionStorage.setItem(REMEMBERED, wallet.name);
    } catch (e) { /* see above */ }
}

/* -- talking to this server ------------------------------------------------ */

export async function post(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        // Same-origin by construction (§10.3); stated so it stays that way.
        credentials: 'same-origin',
        body: JSON.stringify(body || {}),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok && !payload.pending) {
        throw new Error(payload.message || 'the server refused that');
    }
    return payload;
}

/* -- the libraries --------------------------------------------------------- */

/**
 * kit ships a self-contained IIFE with no bare specifiers, so it loads with a
 * script tag from this origin and nothing else (§10.3, §12.2). It is not a
 * module, so it cannot be `import`ed.
 */
function loadKit() {
    if (globalThis.solanaWeb3) return Promise.resolve(globalThis.solanaWeb3);
    return new Promise((resolve, reject) => {
        const tag = document.createElement('script');
        tag.src = '/vendor/solana-kit/kit.min.js';
        tag.addEventListener('load', () => {
            if (globalThis.solanaWeb3) resolve(globalThis.solanaWeb3);
            else reject(new Error('kit loaded but exposed no global'));
        });
        tag.addEventListener('error', () => reject(new Error('could not load kit from this origin — has bin/vendor-assets been run?')));
        document.head.append(tag);
    });
}

/** sol-pay's own package: `wasm-pack --target web` output, ESM beside its .wasm. */
async function loadPay(programAddress, tokenProgram) {
    const module = await import('/vendor/sol-pay-client/sol_pay_client.js');
    await module.default();
    const pay = new module.PayOnChain(programAddress);
    return tokenProgram && tokenProgram !== pay.tokenProgram ? pay.withTokenProgram(tokenProgram) : pay;
}

/* -- the wallet's account -------------------------------------------------- */

/**
 * The wallet account object, not just the address: `signAndSendTransaction`
 * takes the account it connected, and a wallet may hold several.
 *
 * **A freshly loaded page has no connected accounts.** `signIn` connects, but
 * every step that follows it arrives after a navigation, and the wallet object
 * the new page discovers starts with an empty `accounts` array until this page
 * connects for itself. So reconnect before looking — silently first, which is
 * what a wallet does for a site the reader has already authorized, and with a
 * prompt only if that fails.
 *
 * Call this before anything else in a click, so the prompt (when there is one)
 * is still inside the user gesture Android requires.
 */
export async function accountFor(wallet, address) {
    const find = () => (wallet.accounts || []).find((a) => a.address === address);

    let match = find();
    if (match) return match;

    const connect = (wallet.features || {})[CONNECT];
    if (connect) {
        try {
            await connect.connect({ silent: true });
        } catch (e) {
            // Silent reconnect is a courtesy, not a contract. Fall through.
        }
        match = find();
        if (match) return match;

        await connect.connect();
        match = find();
        if (match) return match;
    }

    // Naming both sides: the reader needs to know *which* account this site is
    // waiting for, which "reconnect" never told them.
    const on = (wallet.accounts || []).map((a) => shorten(a.address)).join(', ');
    throw new Error(
        'this site identified ' + shorten(address) + ', but your wallet is on '
        + (on || 'no account')
        + '. Switch to that account in your wallet, or forget the stored wallet on the meter and connect again.',
    );
}

/* -- compile and send ------------------------------------------------------ */

/**
 * Instructions in, signature out.
 *
 * The instructions come out of the wasm client already shaped like kit's
 * `IInstruction` — `{ programAddress, accounts: [{ address, role }], data }` —
 * so nothing adapts between the two.
 *
 * Legacy messages, deliberately: these transactions have a handful of accounts
 * and one signer, so versioned messages and lookup tables buy nothing, and
 * legacy is what every wallet and the PHP side both speak.
 */
export async function sign(ctx, kit, wallet, account, prep, instructions, preparedAt) {
    ctx.trace.instructions = instructions;

    const message = kit.pipe(
        kit.createTransactionMessage({ version: 'legacy' }),
        (m) => kit.setTransactionMessageFeePayer(prep.payer, m),
        (m) => kit.setTransactionMessageLifetimeUsingBlockhash({
            blockhash: prep.blockhash,
            lastValidBlockHeight: BigInt(prep.lastValidBlockHeight ?? 0),
        }, m),
        (m) => kit.appendTransactionMessageInstructions(instructions, m),
    );

    const bytes = new Uint8Array(kit.getTransactionEncoder().encode(kit.compileTransaction(message)));
    ctx.trace.wire = toBase64(bytes);
    ctx.trace.timings.builtMsAfterPrepare = Date.now() - preparedAt;

    ctx.say('Approve it in ' + wallet.name + '…');
    ctx.trace.timings.handedToWalletMsAfterPrepare = Date.now() - preparedAt;

    const signature = await signAndSend(wallet, account, prep.chain, bytes);
    ctx.trace.timings.walletReturnedMsAfterPrepare = Date.now() - preparedAt;

    return signatureToString(signature);
}

/* -- the two flows both screens share -------------------------------------- */

/**
 * `set_meter` → `authorize`: open a contract, or renew one.
 *
 * The same function for both because the program treats them as the same
 * shape — an approval paired with a contract write, in that order — and the
 * server decides which by looking at the chain, not by being told. The meter
 * panel calls this on an article; `manage_meter` calls it to renew.
 */
export async function openOrRenew(ctx, wallet, limitText) {
    // First, and before any server round trip: get the account back. A reload
    // separates identifying from authorizing, so the wallet has to be
    // reconnected, and doing it here keeps any prompt inside the click that
    // started this.
    ctx.say('Reconnecting to ' + wallet.name + '…');
    const account = await accountFor(wallet, ctx.payer);

    ctx.say('Preparing…');
    const [pay, kit] = await ctx.preload();

    const preparedAt = Date.now();
    const prep = await post('/meter/prepare', { limit: limitText });
    ctx.trace.prepare = prep;
    ctx.trace.timings.preparedAt = new Date(preparedAt).toISOString();

    if (prep.payer !== account.address) {
        // The session moved under us — the wallet was forgotten in another tab, most likely.
        throw new Error('this site is now identifying a different wallet; reload the page');
    }

    const instructions = prep.action === 'renew'
        ? pay.approveAndRenew(prep.payerTokenAccount, prep.mint, prep.payer, prep.site, BigInt(prep.limit), prep.decimals)
        : pay.approveAndOpen(prep.payerTokenAccount, prep.mint, prep.payer, prep.site, BigInt(prep.limit), prep.decimals);

    const signature = await sign(ctx, kit, wallet, account, prep, instructions, preparedAt);

    ctx.say('Sent. Waiting for the chain…');
    return post('/meter/opened', { signature });
}

/* -- odds and ends --------------------------------------------------------- */

export function shorten(address) {
    return address.slice(0, 4) + '…' + address.slice(-4);
}

function toBase64(bytes) {
    let binary = '';
    for (const byte of bytes) binary += String.fromCharCode(byte);
    return btoa(binary);
}

/** Wallets return the signature as bytes; the RPC speaks base58. */
export function signatureToString(signature) {
    if (typeof signature === 'string') return signature;
    const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    const bytes = Array.from(signature);
    let zeros = 0;
    while (zeros < bytes.length && bytes[zeros] === 0) zeros += 1;
    const digits = [0];
    for (let i = zeros; i < bytes.length; i += 1) {
        let carry = bytes[i];
        for (let j = 0; j < digits.length; j += 1) {
            carry += digits[j] << 8;
            digits[j] = carry % 58;
            carry = (carry / 58) | 0;
        }
        while (carry > 0) {
            digits.push(carry % 58);
            carry = (carry / 58) | 0;
        }
    }
    let out = '1'.repeat(zeros);
    for (let i = digits.length - 1; i >= 0; i -= 1) out += ALPHABET[digits[i]];
    return out;
}

/* -- writing failures down ------------------------------------------------- */

/**
 * Wallets report every failure as one generic sentence — Phantom says
 * "Unexpected error" for a cluster that refused the send, an expired blockhash
 * and a failed simulation alike. Asking someone to transcribe a console is a
 * poor substitute for the page writing down what it knows: the prepared
 * payload, the instructions it built, the exact bytes the wallet was handed,
 * and how long each step took. The timings matter most — a blockhash that
 * expired while the reader read the approval screen looks exactly like a
 * wallet bug until you can see the clock.
 *
 * Same destination as the wallet diagnostics: var/wallet-reports/.
 */
async function report(ctx, error) {
    try {
        const response = await fetch('/diagnostics/report', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                kind: 'meter-failure',
                at: new Date().toISOString(),
                error: describeError(error),
                timings: ctx.trace.timings,
                prepare: ctx.trace.prepare,
                instructions: (ctx.trace.instructions || []).map((ix) => ({
                    programAddress: ix.programAddress,
                    accounts: ix.accounts,
                    dataLength: ix.data && ix.data.length,
                })),
                wire: ctx.trace.wire,
                page: { href: window.location.href, userAgent: navigator.userAgent },
            }),
        });
        const payload = await response.json().catch(() => ({}));
        if (payload.path && ctx.status) {
            ctx.status.textContent += ' — written to ' + payload.path;
        }
    } catch (e) {
        // A failure to report a failure is not worth a second message.
    }
}

/**
 * Everything an error object will admit to. `message` alone loses the wallet's
 * own `code`, and a `cause` chain is where an RPC's refusal usually hides.
 */
function describeError(error) {
    if (!error || typeof error !== 'object') return { value: String(error) };

    const out = {
        name: error.name,
        message: error.message,
        code: error.code,
        stack: typeof error.stack === 'string' ? error.stack.split('\n').slice(0, 6) : undefined,
    };
    for (const key of Object.getOwnPropertyNames(error)) {
        if (!(key in out) && key !== 'stack') {
            try {
                out[key] = JSON.parse(JSON.stringify(error[key]));
            } catch (e) {
                out[key] = String(error[key]);
            }
        }
    }
    if (error.cause) out.cause = describeError(error.cause);
    return out;
}
