/**
 * The meter panel's two steps.
 *
 * Step one identifies: `signIn`, verified on the server, a session cookie.
 * Step two authorizes: the wasm client builds `approve_and_open`, kit compiles
 * the message, the wallet signs and sends it. Each step is one click and one
 * wallet dialog, because every wallet call has to originate from a real user
 * gesture and after the first `await` the second call is not inside one — §6.3.
 *
 * Neither library is loaded until it is needed. A reader who never authorizes
 * never downloads kit.
 */

import { CONNECT, onWalletsChanged, signAndSend, signIn, toBase64, usable, wallets, wasDeclined } from './wallet.js';

const panel = document.querySelector('[data-meter]');
// The chain this site is on. A wallet that does not speak it is not a
// candidate, however promising its name (see wallet.js: one extension
// registers one wallet per network and they share a name).
const CHAIN = (panel && panel.dataset.chain) || null;
// The address this site identified, from the session. Step two has to talk to
// the wallet about *this* account and no other.
const PAYER = (panel && panel.dataset.payer) || null;
const PROGRAM = (panel && panel.dataset.program) || null;
const TOKEN_PROGRAM = (panel && panel.dataset.tokenProgram) || null;
const status = panel && panel.querySelector('[data-meter-status]');

/** For the console, when something goes wrong on a machine Claude cannot reach. */
const trace = { prepare: null, instructions: null, wire: null, timings: {}, error: null };
window.__newsprint = trace;

const say = (message, kind = 'note') => {
    if (!status) return;
    status.textContent = message;
    status.dataset.kind = kind;
    status.hidden = message === '';
};

const post = async (url, body) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body || {}),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok && !payload.pending) {
        throw new Error(payload.message || 'the server refused that');
    }
    return payload;
};

/* -- which wallet ---------------------------------------------------------- */

const REMEMBERED = 'newsprint.wallet';

/**
 * Which wallet to talk to on step two.
 *
 * Remembered per tab, not per reader: it is the *name of a browser extension*,
 * it never reaches the server, and it is gone when the tab closes. The site's
 * record of you is still one address and a session id.
 */
function remembered() {
    let name = null;
    try {
        name = window.sessionStorage.getItem(REMEMBERED);
    } catch (e) {
        // Private windows and blocked site data both throw here; the fallback
        // is asking again, which costs one click.
    }
    if (!name) return usable(CHAIN)[0] || null;
    return usable(CHAIN).find((w) => w.name === name) || usable(CHAIN)[0] || null;
}

function remember(wallet) {
    try {
        window.sessionStorage.setItem(REMEMBERED, wallet.name);
    } catch (e) { /* see above */ }
}

/* -- step one: identify ---------------------------------------------------- */

const identify = panel && panel.querySelector('[data-identify]');
const choices = panel && panel.querySelector('[data-wallet-choices]');

async function runSignIn(wallet) {
    say('Waiting for ' + wallet.name + '…');
    const challenge = await post('/signin/challenge');
    const output = await signIn(wallet, challenge.input);

    await post('/signin/verify', {
        nonce: challenge.input.nonce,
        address: output.account.address,
        signedMessage: toBase64(output.signedMessage),
        signature: toBase64(output.signature),
    });

    remember(wallet);
    // The panel's next state is a server decision — it depends on the contract
    // and the balance, which are accounts and not something the browser knows.
    window.location.reload();
}

function drawChoices() {
    if (!choices) return;
    const ready = usable(CHAIN);
    choices.replaceChildren();

    if (ready.length === 0) {
        const none = document.createElement('p');
        none.className = 'pending';
        none.textContent = wallets().length === 0
            ? 'No wallet has announced itself to this page.'
            : 'A wallet is here, but it does not offer Sign In With Solana.';
        const where = document.createElement('a');
        where.href = '/diagnostics/wallets';
        where.textContent = 'What this page can see';
        choices.append(none, where);
        choices.hidden = false;
        return;
    }

    for (const wallet of ready) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'wallet';
        if (wallet.icon) {
            const icon = document.createElement('img');
            icon.src = wallet.icon;
            icon.alt = '';
            icon.width = 24;
            icon.height = 24;
            button.append(icon);
        }
        button.append(document.createTextNode(wallet.name));
        button.addEventListener('click', () => guard(button, () => runSignIn(wallet)));
        choices.append(button);
    }
    choices.hidden = false;
}

if (identify) {
    identify.addEventListener('click', () => {
        const ready = usable(CHAIN);
        // One wallet is the common case, and making the reader pick from a list
        // of one is a click that buys nothing. This click is still the gesture
        // the wallet call originates from.
        if (ready.length === 1) {
            guard(identify, () => runSignIn(ready[0]));
            return;
        }
        identify.hidden = true;
        drawChoices();
    });
    onWalletsChanged(() => { if (choices && !choices.hidden) drawChoices(); });
}

/* -- the faucet: no wallet involved ---------------------------------------- */

const faucet = panel && panel.querySelector('[data-faucet]');
if (faucet) {
    faucet.addEventListener('click', () => guard(faucet, async () => {
        say('Minting…');
        const result = await post('/faucet');
        if (!result.granted) throw new Error(result.reason || 'the faucet refused');
        window.location.reload();
    }));
}

/* -- step two: authorize --------------------------------------------------- */

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

/**
 * Start both downloads as soon as the panel is on screen.
 *
 * 221 KB of kit and 125 KB of wasm were being fetched *after* the blockhash,
 * which put them inside the window §6.3 warns about: a blockhash has to
 * survive the reader going into their wallet, and every byte downloaded
 * between the two spends part of its life. Download first, fetch the
 * blockhash second.
 *
 * Not a wallet call, so it needs no user gesture.
 */
let libraries = null;
function preload() {
    if (libraries === null && PROGRAM) {
        libraries = Promise.all([loadPay(PROGRAM, TOKEN_PROGRAM), loadKit()]);
        // Swallowed here; the click reports it when it actually needs them.
        libraries.catch(() => {});
    }
    return libraries;
}

const authorize = panel && panel.querySelector('[data-authorize]');
const limitField = panel && panel.querySelector('[data-limit]');

async function runAuthorize(wallet) {
    // First, and before any server round trip: get the account back. A reload
    // separates step one from step two, so the wallet has to be reconnected,
    // and doing it here keeps any prompt inside the click that started this.
    say('Reconnecting to ' + wallet.name + '…');
    const account = await accountFor(wallet, PAYER);

    say('Preparing…');

    // The blockhash comes back from here, fetched immediately before the
    // handoff: it has to survive the reader spending thirty seconds in a
    // wallet, and one fetched at page render has already spent part of its life.
    // Libraries first, blockhash second — see preload().
    await preload();

    const preparedAt = Date.now();
    const prep = await post('/meter/prepare', { limit: limitField.value.trim() });
    trace.timings.preparedAt = new Date(preparedAt).toISOString();
    trace.prepare = prep;

    if (prep.payer !== account.address) {
        // The session moved under us — signed out in another tab, most likely.
        throw new Error('this site is now identifying a different wallet; reload the page');
    }

    const [pay, kit] = await preload();

    // Instructions come out of the wasm client already shaped like kit's
    // IInstruction — { programAddress, accounts: [{ address, role }], data } —
    // so nothing adapts between the two.
    const instructions = prep.action === 'renew'
        ? pay.approveAndRenew(prep.payerTokenAccount, prep.mint, prep.payer, prep.site, BigInt(prep.limit), prep.decimals)
        : pay.approveAndOpen(prep.payerTokenAccount, prep.mint, prep.payer, prep.site, BigInt(prep.limit), prep.decimals);
    trace.instructions = instructions;

    // Legacy, deliberately: this transaction has eight accounts and one signer,
    // so versioned messages and lookup tables buy it nothing, and legacy is
    // what every wallet and the PHP side both speak.
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
    trace.wire = toBase64(bytes);
    trace.timings.builtMsAfterPrepare = Date.now() - preparedAt;

    say('Approve it in ' + wallet.name + '…');
    trace.timings.handedToWalletMsAfterPrepare = Date.now() - preparedAt;
    const signature = await signAndSend(wallet, account, prep.chain, bytes);

    trace.timings.walletReturnedMsAfterPrepare = Date.now() - preparedAt;
    say('Sent. Waiting for the chain…');
    const result = await post('/meter/opened', { signature: signatureToString(signature) });
    if (result.pending) {
        say(result.message, 'note');
        return;
    }
    window.location.reload();
}

/**
 * The wallet account object, not just the address: `signAndSendTransaction`
 * takes the account it connected, and a wallet may hold several.
 *
 * **A freshly loaded page has no connected accounts.** `signIn` connects, but
 * step one ends in a reload, and the wallet object the new page discovers
 * starts with an empty `accounts` array until this page connects for itself.
 * So step two reconnects before it looks — silently first, which is what a
 * wallet does for a site the reader has already authorized, and with a prompt
 * only if that fails.
 *
 * This runs before anything else in the click, so the prompt (when there is
 * one) is still inside the user gesture Android requires.
 */
async function accountFor(wallet, address) {
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

    // Naming both sides, because "reconnect" was useless advice: the reader
    // needs to know *which* account this site is waiting for.
    const on = (wallet.accounts || []).map((a) => shorten(a.address)).join(', ');
    throw new Error(
        'this site identified ' + shorten(address) + ', but your wallet is on '
        + (on || 'no account')
        + '. Switch to that account in your wallet, or sign out and connect again.',
    );
}

function shorten(address) {
    return address.slice(0, 4) + '…' + address.slice(-4);
}

/** Wallets return the signature as bytes; the RPC speaks base58. */
function signatureToString(signature) {
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

if (authorize) {
    // The downloads start now, not on the click.
    if (window.requestIdleCallback) window.requestIdleCallback(preload);
    else window.setTimeout(preload, 0);

    authorize.addEventListener('click', () => {
        const wallet = remembered();
        if (!wallet) {
            say('That wallet is no longer here. Reload and connect again.', 'error');
            return;
        }
        guard(authorize, () => runAuthorize(wallet));
    });
}

/* -- one place where things go wrong --------------------------------------- */

async function guard(button, work) {
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
        await report(error);
    }
}

/**
 * Write the failure down, on disk, where Claude can read it.
 *
 * Wallets report every failure as one generic sentence — Phantom says
 * "Unexpected error" for a cluster that refused the send, an expired
 * blockhash and a failed simulation alike. Asking someone to transcribe a
 * console is a poor substitute for the page writing down what it knows:
 * the prepared payload, the instructions it built, the exact bytes the
 * wallet was handed, and how long each step took. The timings matter most —
 * a blockhash that expired while the reader read the approval screen looks
 * exactly like a wallet bug until you can see the clock.
 *
 * Same destination as the wallet diagnostics: var/wallet-reports/.
 */
async function report(error) {
    try {
        const response = await fetch('/diagnostics/report', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                kind: 'meter-failure',
                at: new Date().toISOString(),
                error: describeError(error),
                timings: trace.timings,
                prepare: trace.prepare,
                instructions: (trace.instructions || []).map((ix) => ({
                    programAddress: ix.programAddress,
                    accounts: ix.accounts,
                    dataLength: ix.data && ix.data.length,
                })),
                wire: trace.wire,
                page: { href: window.location.href, userAgent: navigator.userAgent },
            }),
        });
        const payload = await response.json().catch(() => ({}));
        if (payload.path && status) {
            status.textContent += ' — written to ' + payload.path;
        }
    } catch (e) {
        // A failure to report a failure is not worth a second message.
    }
}

/**
 * Everything an error object will admit to. `message` alone loses the
 * wallet's own `code`, and a `cause` chain is where an RPC's refusal usually
 * hides.
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
