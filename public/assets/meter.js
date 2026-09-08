/**
 * The meter panel's two steps, on a metered article.
 *
 * Step one identifies: `signIn`, verified on the server, a session cookie.
 * Step two authorizes: the wasm client builds `approve_and_open`, kit compiles
 * the message, the wallet signs and sends it. Each step is one click and one
 * wallet dialog, because every wallet call has to originate from a real user
 * gesture and after the first `await` the second call is not inside one — §6.3.
 *
 * The shared machinery — loading the libraries, reconnecting the account,
 * compiling, sending, and writing failures down — is in `tx.js`, which
 * `manage.js` uses too.
 */

import { onWalletsChanged, signIn, usable, wallets } from './wallet.js';
import { context, openOrRenew, post, rememberWallet, rememberedWallet } from './tx.js';

const panel = document.querySelector('[data-meter]');
const ctx = context(panel);
const { say, guard } = ctx;

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
        signedMessage: base64(output.signedMessage),
        signature: base64(output.signature),
    });

    rememberWallet(wallet);
    // The panel's next state is a server decision — it depends on the contract
    // and the balance, which are accounts and not something the browser knows.
    window.location.reload();
}

function base64(bytes) {
    let binary = '';
    for (const byte of bytes) binary += String.fromCharCode(byte);
    return btoa(binary);
}

/**
 * The one refusal a reader can undo themselves.
 *
 * Measured 2026-09-08: Brave with `brave://settings/wallet` on **Brave
 * Wallet** registers only Brave Wallet, which has no `solana:signIn`, so §5
 * refuses — correctly — and the reader is told their wallet is not supported
 * while a perfectly good Phantom sits one setting away, never having reached
 * the page. Changing the setting to *Extensions (Brave Wallet fallback)* was
 * the entire fix; nothing in this repository was wrong.
 *
 * That makes this the cheapest sentence on the site. The alternative is a
 * reader concluding the demo is broken, which is the one conclusion a
 * demonstration cannot afford, and it costs a paragraph shown to nobody else.
 *
 * `navigator.brave` is the same predicate `/diagnostics/wallets` records as
 * `browser.brave`, and it was `true` in the report that produced this.
 *
 * **The address is text, not a link, and that is not a style choice.** A page
 * cannot navigate to `brave://` — the browser refuses it — so a link here
 * would look like the fix and do nothing, which is worse than the dead end it
 * was meant to clear.
 */
function browserWalletHint() {
    if (!navigator.brave) return null;

    const hint = document.createElement('p');
    hint.className = 'pending';
    hint.append(document.createTextNode(
        'Brave ships its own wallet, and it can stand in front of an extension: '
        + 'the page then sees only Brave Wallet, which does not offer sign-in. '
        + 'If you have an extension wallet installed, open ',
    ));
    const path = document.createElement('code');
    path.textContent = 'brave://settings/wallet';
    hint.append(path);
    hint.append(document.createTextNode(
        ' — it has to be pasted into the address bar, as a page is not allowed to link there — '
        + 'and set the default Solana wallet to “Extensions (Brave Wallet fallback)”. Then reload this page.',
    ));

    return hint;
}

function drawChoices() {
    if (!choices) return;
    const ready = usable(ctx.chain);
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

        const hint = browserWalletHint();
        choices.append(none, ...(hint ? [hint] : []), where);
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
        const ready = usable(ctx.chain);
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

const authorize = panel && panel.querySelector('[data-authorize]');
const limitField = panel && panel.querySelector('[data-limit]');

async function runAuthorize(wallet) {
    const result = await openOrRenew(ctx, wallet, limitField.value.trim());

    if (result.pending) {
        // §7.3's shape: sent, not confirmed inside the window. Not an error
        // and not a success — the reader reloads and the chain answers.
        say(result.message, 'note');
        return;
    }
    window.location.reload();
}

if (authorize) {
    // The downloads start now, not on the click.
    if (window.requestIdleCallback) window.requestIdleCallback(ctx.preload);
    else window.setTimeout(ctx.preload, 0);

    authorize.addEventListener('click', () => {
        const wallet = rememberedWallet(ctx);
        if (!wallet) {
            say('That wallet is no longer here. Reload and connect again.', 'error');
            return;
        }
        guard(authorize, () => runAuthorize(wallet));
    });
}

