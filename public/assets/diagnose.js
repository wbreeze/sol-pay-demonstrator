/**
 * What the wallets in this browser actually say about themselves.
 *
 * Written because the sign-in screen refused Phantom in both Brave and Firefox
 * with "does not offer Sign In With Solana", and that refusal was computed
 * from a feature list nobody had looked at. Claude is working from
 * specifications; this page is the part that cannot be read from a
 * specification.
 *
 * It deliberately filters nothing. Every wallet that registers is listed with
 * every feature key it advertises, and each one gets a button that calls
 * `signIn` regardless of whether the feature is advertised — because "the
 * feature is missing" and "the feature is present under another name" and "the
 * call works anyway" are three different findings and the site's own check
 * cannot tell them apart.
 */

const events = [];
const registered = [];
const attempts = [];
const started = performance.now();

const out = document.querySelector('[data-report]');
const list = document.querySelector('[data-wallet-list]');
const status = document.querySelector('[data-diag-status]');

function note(what, detail) {
    events.push({ at: Math.round(performance.now() - started), what, detail });
}

function add(wallet, via) {
    registered.push({ wallet, via, at: Math.round(performance.now() - started) });
    note('register', { name: wallet && wallet.name, via });
    draw();
}

const api = {
    register: (...ws) => {
        for (const w of ws) add(w, 'register()');
        return () => {};
    },
};

window.addEventListener('wallet-standard:register-wallet', (event) => {
    note('register-wallet event', { detailType: typeof event.detail });
    try {
        event.detail(api);
    } catch (e) {
        note('register-wallet threw', String(e));
    }
});


/** Everything about one wallet, with nothing interpreted away. */
function describe(entry) {
    const w = entry.wallet || {};
    let features = {};
    try {
        for (const [key, value] of Object.entries(w.features || {})) {
            features[key] = {
                version: value && value.version,
                methods: value && typeof value === 'object'
                    ? Object.keys(value).filter((k) => typeof value[k] === 'function')
                    : [],
            };
        }
    } catch (e) {
        features = { '<unreadable>': String(e) };
    }

    return {
        name: w.name,
        version: w.version,
        via: entry.via,
        atMs: entry.at,
        hasIcon: typeof w.icon === 'string',
        iconScheme: typeof w.icon === 'string' ? w.icon.slice(0, w.icon.indexOf(':') + 1) : null,
        chains: Array.isArray(w.chains) ? w.chains.slice() : w.chains,
        accounts: Array.isArray(w.accounts) ? w.accounts.map((a) => ({ address: a.address, chains: a.chains, features: a.features })) : [],
        featureKeys: Object.keys(features),
        features,
    };
}

function report() {
    return {
        generatedAt: new Date().toISOString(),
        page: {
            href: window.location.href,
            origin: window.location.origin,
            isSecureContext: window.isSecureContext,
        },
        browser: {
            userAgent: navigator.userAgent,
            brave: Boolean(navigator.brave),
            vendor: navigator.vendor,
        },
        // Legacy injected providers, which is how a wallet that predates
        // Wallet Standard would show up instead.
        injected: {
            phantom: typeof window.phantom,
            phantomSolana: Boolean(window.phantom && window.phantom.solana),
            solana: typeof window.solana,
            solanaIsPhantom: Boolean(window.solana && window.solana.isPhantom),
            solflare: typeof window.solflare,
            braveSolana: Boolean(window.braveSolana),
        },
        events,
        wallets: registered.map(describe),
        attempts,
    };
}

async function trySignIn(entry) {
    const w = entry.wallet;
    const record = { wallet: w && w.name, at: new Date().toISOString() };
    try {
        const response = await fetch('/signin/challenge', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: '{}',
        });
        const { input } = await response.json();
        record.input = input;

        const feature = (w.features || {})['solana:signIn'];
        if (!feature) {
            record.outcome = 'no solana:signIn feature to call';
            attempts.push(record);
            draw();
            return;
        }

        const outputs = await feature.signIn(input);
        const out = outputs && outputs[0];
        record.outcome = 'signed';
        record.account = out && out.account && out.account.address;
        record.accountChains = out && out.account && out.account.chains;
        record.accountFeatures = out && out.account && out.account.features;
        record.signatureBytes = out && out.signature && out.signature.length;
        record.signedMessage = out && out.signedMessage
            ? new TextDecoder().decode(out.signedMessage)
            : null;
    } catch (e) {
        record.outcome = 'threw';
        record.error = String((e && e.message) || e);
        record.errorName = e && e.name;
    }
    attempts.push(record);
    draw();
}

async function tryConnect(entry) {
    const w = entry.wallet;
    const record = { wallet: w && w.name, at: new Date().toISOString(), call: 'standard:connect' };
    try {
        const feature = (w.features || {})['standard:connect'];
        if (!feature) {
            record.outcome = 'no standard:connect feature';
        } else {
            const out = await feature.connect();
            record.outcome = 'connected';
            record.accounts = (out.accounts || []).map((a) => ({ address: a.address, chains: a.chains, features: a.features }));
        }
    } catch (e) {
        record.outcome = 'threw';
        record.error = String((e && e.message) || e);
    }
    attempts.push(record);
    draw();
}

function draw() {
    if (!out) return;
    out.textContent = JSON.stringify(report(), null, 2);

    list.replaceChildren();
    for (const entry of registered) {
        const row = document.createElement('div');
        row.className = 'gate';

        const heading = document.createElement('h2');
        heading.textContent = (entry.wallet && entry.wallet.name) || '(unnamed)';
        row.append(heading);

        const keys = Object.keys((entry.wallet && entry.wallet.features) || {});
        const summary = document.createElement('p');
        summary.innerHTML = '<code>' + keys.join('</code> <code>') + '</code>';
        row.append(summary);

        const signIn = document.createElement('button');
        signIn.type = 'button';
        signIn.className = 'wallet';
        signIn.textContent = 'call solana:signIn';
        signIn.addEventListener('click', () => trySignIn(entry));
        row.append(signIn);

        const connect = document.createElement('button');
        connect.type = 'button';
        connect.className = 'secondary';
        connect.textContent = 'call standard:connect';
        connect.addEventListener('click', () => tryConnect(entry));
        row.append(connect);

        list.append(row);
    }
}

document.querySelector('[data-save]')?.addEventListener('click', async () => {
    const response = await fetch('/diagnostics/report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(report()),
    });
    const payload = await response.json().catch(() => ({}));
    status.hidden = false;
    status.textContent = response.ok
        ? 'Written to ' + payload.path
        : 'Could not write it: ' + (payload.message || response.status);
});

document.querySelector('[data-copy]')?.addEventListener('click', async () => {
    await navigator.clipboard.writeText(JSON.stringify(report(), null, 2));
    status.hidden = false;
    status.textContent = 'Copied.';
});

// Dispatched last, so that everything a registration reaches — draw(), report(),
// the DOM handles — already exists. A wallet registering into a half-evaluated
// module throws a ReferenceError nobody would recognise.
note('dispatching app-ready', null);
window.dispatchEvent(new CustomEvent('wallet-standard:app-ready', { detail: api }));

draw();
window.setTimeout(draw, 500);
window.setTimeout(draw, 2000);
