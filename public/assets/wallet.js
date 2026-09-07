/**
 * Wallet Standard, directly (SPEC §12.3).
 *
 * Discovery is two `window` events and no dependency. The page dispatches
 * `wallet-standard:app-ready` carrying a `register` function, for wallets that
 * loaded before this script; and it listens for
 * `wallet-standard:register-wallet`, whose detail is a callback the page
 * invokes with the same API, for wallets that load after. `@wallet-standard/app`
 * is a wrapper over exactly this. Once a wallet has registered, `connect`,
 * `signIn` and `signAndSendTransaction` are functions it supplied.
 *
 * Four properties here are for §6.3's Android path, which is not built yet.
 * They cost nothing now and are expensive to retrofit:
 *
 * 1. **Discovery is "whatever registers".** Mobile Wallet Adapter registers as
 *    an ordinary Wallet Standard wallet, so `registerMwa()` becomes one more
 *    entry in this same list and nothing in this file changes.
 * 2. **Nothing here connects on load.** Every wallet call in this module is
 *    reached from a click handler, because Android Chrome's trusted-event
 *    policy blocks the intent navigation otherwise.
 * 3. **Registration is client-side only**, which a module script guarantees.
 * 4. **No state is cached across a call.** A wallet that comes back from an
 *    app switch is re-read rather than assumed.
 */

const registered = [];
const listeners = new Set();

function add(wallet) {
    // **Keyed by object, not by name.** One extension registers several
    // wallets, and they share a name: Phantom registers three — Solana,
    // Bitcoin and Sui — all called "Phantom", all at 0 ms. Keying by name kept
    // only the last, which is the Sui one, which has no `solana:signIn`; the
    // site then told a reader with a working Phantom that their wallet did not
    // support sign-in. Measured in Firefox 155, 2026-09-07.
    //
    // Re-registration of the *same* wallet object is what the identity check
    // is actually for, and that is what this now checks.
    if (!registered.includes(wallet)) {
        registered.push(wallet);
    }
    for (const listener of listeners) listener(wallets());
}

const api = { register: (...ws) => { for (const w of ws) add(w); return () => {}; } };

window.addEventListener('wallet-standard:register-wallet', (event) => {
    // The detail is a callback, not a wallet: the wallet hands us a function
    // and we hand it the registry.
    try { event.detail(api); } catch (e) { console.error('[newsprint] a wallet failed to register', e); }
});

window.dispatchEvent(new CustomEvent('wallet-standard:app-ready', { detail: api }));

/** Every wallet that has registered, in registration order. */
export function wallets() {
    return registered.slice();
}

/** Called whenever the list changes; a wallet may register after the page has drawn. */
export function onWalletsChanged(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

export const SIGN_IN = 'solana:signIn';
export const SIGN_AND_SEND = 'solana:signAndSendTransaction';
export const CONNECT = 'standard:connect';

export function supports(wallet, ...features) {
    return features.every((f) => wallet.features && f in wallet.features);
}

/**
 * The wallets this site can actually use, for a given chain.
 *
 * §5 requires `signIn` with no fallback, so a wallet without it is not a
 * degraded experience here — it is unsupported, and the screen says so and
 * names one that works. That is a demonstration's prerogative and not a
 * product's, which is why the refusal is explicit rather than a disabled
 * button.
 */
export function usable(chain = null) {
    return registered.filter((w) => {
        if (!supports(w, SIGN_IN, SIGN_AND_SEND)) return false;
        const chains = chainsOf(w);
        // A wallet is only usable here if it speaks *this* chain. Without
        // this, a same-named sibling registered by the same extension for
        // another network looks like a candidate — which is how three
        // Phantoms became one unusable one.
        return chain === null ? chains.some((c) => c.startsWith('solana:')) : chains.includes(chain);
    });
}

export function chainsOf(wallet) {
    return (wallet.chains || []).slice();
}

/**
 * Sign in, in one wallet interaction.
 *
 * Note what is *not* here: no `connect` first. `signIn` connects and signs in
 * one step, which is why §5 requires it — one user gesture rather than two,
 * and §6.3 wants every wallet call to originate from a gesture on Android.
 *
 * The wallet returns an array because it may sign in more than one account;
 * this site takes the first and the server binds the session to the address
 * that actually signed.
 */
export async function signIn(wallet, input) {
    const outputs = await wallet.features[SIGN_IN].signIn(input);
    if (!outputs || outputs.length === 0) throw new Error('the wallet returned no sign-in');
    return outputs[0];
}

/**
 * Sign and send serialized transaction bytes.
 *
 * Every transaction the payer signs in sol-pay has exactly one signer — the
 * payer — and the site never counter-signs and never needs the signed bytes
 * back. So all of them go through `signAndSendTransaction`, which is the
 * method MWA 2.0 guarantees and which `signTransactions` is deprecated in
 * favour of. A design that needed a counter-signature would have run into that
 * immediately.
 */
export async function signAndSend(wallet, account, chain, transactionBytes) {
    const outputs = await wallet.features[SIGN_AND_SEND].signAndSendTransaction({
        account,
        chain,
        transaction: transactionBytes,
    });
    if (!outputs || outputs.length === 0) throw new Error('the wallet returned no signature');
    return outputs[0].signature;
}

/** base64 for the wire, because JSON has no bytes. */
export function toBase64(bytes) {
    let binary = '';
    for (const byte of bytes) binary += String.fromCharCode(byte);
    return btoa(binary);
}

export function fromBase64(text) {
    const binary = atob(text);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return bytes;
}

/** A wallet dialog the reader dismissed is an ordinary outcome, not an error. */
export function wasDeclined(error) {
    const message = String((error && error.message) || error || '').toLowerCase();
    return message.includes('reject') || message.includes('declin') || message.includes('cancel') || message.includes('denied');
}
