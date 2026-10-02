/**
 * This browser's key, and the three things the page does with it (SPEC §5).
 *
 * The key is Ed25519, made by WebCrypto **non-extractable** and kept in
 * IndexedDB, one per site per device (§5.1). A script in the page can use it
 * while the page is open and cannot carry it away. Beside it the page keeps
 * the address of the meter that names it, once the page has been told.
 *
 * 1. **Bind.** A browser that holds a key and a meter address and arrives with
 *    no session proves the key, and the server binds a session (§5.3). It
 *    signs `Newsprint key proof\n`, then this origin, then a nonce the server
 *    issued (§5.2).
 * 2. **Close.** The server compiles `close_meter`; the key signs those bytes;
 *    the server adds the authority's signature and sends. Then the key and the
 *    meter address are deleted here (§5.4).
 * 3. **Set up** (§6.3): post the key and the panel's answers, show the link
 *    a wallet fetches, and on *continue* prove the key against the meter the
 *    wallet opened. Renewing and *add to the fund* are the same scan.
 *
 * No library. WebCrypto signs, and the server composes every byte the key
 * signs (§12.2). Listeners are on the document, because a module runs once per
 * document and the panel can arrive later in a swapped answer (`swap.js`).
 */

const PREFIX = 'Newsprint key proof\n';
const DB = 'newsprint';
const STORE = 'browser';

// ---- storage -------------------------------------------------------------

function database() {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open(DB, 1);
    open.onupgradeneeded = () => open.result.createObjectStore(STORE);
    open.onsuccess = () => resolve(open.result);
    open.onerror = () => reject(open.error);
  });
}

async function stored(mode, act) {
  const db = await database();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, mode);
    const request = act(tx.objectStore(STORE));
    tx.oncomplete = () => resolve(request.result);
    tx.onerror = () => reject(tx.error);
  });
}

const read = (name) => stored('readonly', (s) => s.get(name));
const write = (name, value) => stored('readwrite', (s) => s.put(value, name));
const forget = (name) => stored('readwrite', (s) => s.delete(name));

/** The key pair, made on first use. The private half can sign and cannot be read out. */
async function keyPair() {
  const held = await read('key');
  if (held) return held;
  const made = await crypto.subtle.generateKey({ name: 'Ed25519' }, false, ['sign', 'verify']);
  await write('key', made);
  return made;
}

// ---- encodings -----------------------------------------------------------

const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

function base58(bytes) {
  const digits = [0];
  for (const byte of bytes) {
    let carry = byte;
    for (let i = 0; i < digits.length; i++) {
      carry += digits[i] << 8;
      digits[i] = carry % 58;
      carry = (carry / 58) | 0;
    }
    while (carry > 0) {
      digits.push(carry % 58);
      carry = (carry / 58) | 0;
    }
  }
  let out = '';
  for (const byte of bytes) {
    if (byte !== 0) break;
    out += '1';
  }
  for (let i = digits.length - 1; i >= 0; i--) out += ALPHABET[digits[i]];
  return out;
}

const fromHex = (hex) => Uint8Array.from(hex.match(/../g), (pair) => parseInt(pair, 16));
const toBase64 = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)));
const fromBase64 = (text) => Uint8Array.from(atob(text), (c) => c.charCodeAt(0));

async function publicKey(pair) {
  return base58(new Uint8Array(await crypto.subtle.exportKey('raw', pair.publicKey)));
}

async function post(path, payload) {
  const response = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload ?? {}),
  });
  const body = await response.json().catch(() => ({}));
  return { status: response.status, body };
}

// ---- 1. bind --------------------------------------------------------------

/** Prove the key for `meter` and bind a session. Resolves to the server's answer. */
async function prove(meter) {
  const pair = await keyPair();
  const { body: issued } = await post('/key/nonce');
  const signed = new Uint8Array([
    ...new TextEncoder().encode(PREFIX + location.origin),
    ...fromHex(issued.nonce),
  ]);
  const signature = await crypto.subtle.sign('Ed25519', pair.privateKey, signed);
  return post('/key/prove', { meter, nonce: issued.nonce, signature: toBase64(signature) });
}

function say(element, text) {
  if (!element) return;
  element.textContent = text;
  element.hidden = false;
}

/**
 * The refusals that mean this browser no longer holds the meter: it is gone,
 * it names another key, or it is another site's. The address is forgotten so
 * the next visit does not ask again. An expired meter is not among them: it
 * binds, so that this browser can still close it from `/meter`.
 */
const LOST = ['no-meter', 'signature', 'another-site'];

async function bind() {
  const status = document.querySelector('[data-key-bind]');
  if (!status || status.dataset.tried) return;
  status.dataset.tried = '1';

  const meter = await read('meter').catch(() => undefined);
  if (!meter || !(await read('key').catch(() => undefined))) return;

  say(status, 'This browser holds a meter for this site. Proving its key…');
  try {
    const { status: code, body } = await prove(meter);
    if (body.ok) {
      location.reload();
      return;
    }
    if (LOST.includes(body.reason)) await forget('meter');
    say(status, body.message || `The proof was refused (${code}).`);
  } catch (error) {
    say(status, `The proof could not be sent: ${error.message}`);
  }
}

// ---- 2. close -------------------------------------------------------------

async function close(button) {
  const status = document.querySelector('[data-close-status]');
  button.disabled = true;
  say(status, 'Closing the meter…');
  try {
    const pair = await read('key');
    if (!pair) {
      say(status, 'This browser no longer has the key that signs the close.');
      return;
    }
    const prepared = await post('/meter/close/prepare');
    if (!prepared.body.message || prepared.status !== 200) {
      say(status, prepared.body.message || `The close could not be prepared (${prepared.status}).`);
      return;
    }
    const signature = await crypto.subtle.sign('Ed25519', pair.privateKey, fromBase64(prepared.body.message));
    const sent = await post('/meter/close', { signature: toBase64(signature) });
    if (sent.body.ok) {
      await forget('meter');
      await forget('key');
      say(status, 'The meter is closed. This site has forgotten it, and this browser has deleted its key.');
      if (sent.body.signature) {
        const link = document.createElement('a');
        link.href = `https://explorer.solana.com/tx/${sent.body.signature}?cluster=devnet`;
        link.rel = 'noreferrer noopener';
        link.target = '_blank';
        link.textContent = 'The close on chain';
        status.append(' ', link);
      }
      // The meter is gone, so everything that acted on it goes: the close
      // button, and the renew and deposit forms with any scan they started.
      pending = null;
      forgetCameFrom();
      offerBack();
      document.querySelectorAll('[data-close-controls]').forEach((controls) => { controls.hidden = true; });
      return;
    }
    say(status, sent.body.message || `The close was refused (${sent.status}).`);
  } catch (error) {
    say(status, `The close could not be sent: ${error.message}`);
  } finally {
    button.disabled = false;
  }
}

// ---- 3. setup ------------------------------------------------------------

let pending = null;

function region() {
  return document.querySelector('[data-setup-scan]');
}

/** §6.3 step 2: the page's key and the panel's answers; the server answers with the link. */
async function start(form) {
  const scan = region();
  const status = scan?.querySelector('[data-setup-status]');
  const fields = new FormData(form);
  const kind = form.dataset.kind;
  const answers = {
    kind,
    limit: fields.get('limit')?.toString() ?? '0',
    expiry: fields.get('expiry')?.toString() ?? 'day',
    deposit: fields.get('deposit')?.toString() ?? '0',
    index: fields.get('index')?.toString() ?? '0',
  };
  if (kind !== 'deposit') answers.key = await publicKey(await keyPair());

  const { status: code, body } = await post('/meter/setup', answers);
  if (!body.id) {
    // A refused start leaves no scan behind: an earlier link and code would
    // otherwise still be on the page, answering for a setup that is not this one.
    pending = null;
    scan?.querySelectorAll('[data-setup-offer]').forEach((part) => { part.hidden = true; });
    say(status ?? form, body.message || `The setup could not be started (${code}).`);
    if (scan) scan.hidden = false;
    return;
  }

  pending = { id: body.id, kind };
  scan.querySelectorAll('[data-setup-offer]').forEach((part) => { part.hidden = false; });
  const link = scan.querySelector('[data-setup-link]');
  link.href = body.link;
  scan.querySelector('[data-setup-qr]').innerHTML = body.qr ?? '';
  scan.hidden = false;
  say(status, kind === 'deposit'
    ? 'Approve the deposit in the wallet, then continue.'
    : 'Approve the transaction in the wallet, then continue.');
}

/** §12.6: the development wallet signs in place of a phone. */
async function development(button) {
  const status = region()?.querySelector('[data-setup-status]');
  if (!pending) return;
  button.disabled = true;
  say(status, 'The development wallet is signing and sending…');
  try {
    const { status: code, body } = await post(`/pay/${pending.id}/development`);
    say(status, body.ok
      ? `Sent: ${body.message} Now continue.`
      : (body.message || `The development wallet could not send it (${code}).`));
  } finally {
    button.disabled = false;
  }
}

/**
 * §6.3 step 5: one read, on the reader's gesture. For a setup, a fresh nonce
 * signed by the key; the server binds the session when the meter names it.
 */
async function proceed(button) {
  const status = region()?.querySelector('[data-setup-status]');
  if (!pending) return;
  button.disabled = true;
  try {
    const payload = { id: pending.id };
    if (pending.kind !== 'deposit') {
      const pair = await keyPair();
      const { body: issued } = await post('/key/nonce');
      const signed = new Uint8Array([
        ...new TextEncoder().encode(PREFIX + location.origin),
        ...fromHex(issued.nonce),
      ]);
      payload.nonce = issued.nonce;
      payload.signature = toBase64(await crypto.subtle.sign('Ed25519', pair.privateKey, signed));
    }
    const { status: code, body } = await post('/meter/setup/continue', payload);
    if (body.ok) {
      if (body.meter) await write('meter', body.meter);
      // A reader who came to the meter page from an article was on the way
      // to that article. Renewing or adding to the fund was the interruption,
      // so *continue* takes them back to it. Anywhere else, the page reloads.
      const back = cameFrom();
      if (back) {
        forgetCameFrom();
        location.assign(back);
      } else {
        location.reload();
      }
      return;
    }
    say(status, body.message || `Not yet (${code}).`);
  } catch (error) {
    say(status, `Continue could not be sent: ${error.message}`);
  } finally {
    button.disabled = false;
  }
}

// ---- where the reader was going ------------------------------------------

/**
 * The article a reader left to reach the meter page, kept for this tab only.
 * A blocked article sends the reader to `/meter` to renew or to add money, and
 * the article is where they were going. Only this site's own article paths are
 * kept, so nothing else can be made a destination.
 */
const BACK = 'newsprint:back';
const ARTICLE = /^\/a\/[a-z0-9-]+$/;

function rememberCameFrom() {
  if (location.pathname !== '/meter' || !document.referrer) return;
  try {
    const from = new URL(document.referrer);
    if (from.origin !== location.origin) return;
    if (ARTICLE.test(from.pathname)) {
      sessionStorage.setItem(BACK, from.pathname);
    } else if (from.pathname !== '/meter') {
      // Arrived from somewhere that is not an article, so an article kept
      // from an earlier visit is no longer where the reader was going. A
      // reload of this page keeps what it had.
      sessionStorage.removeItem(BACK);
    }
  } catch {
    // No referrer worth reading, or no storage: the page reloads as before.
  }
}

function cameFrom() {
  if (location.pathname !== '/meter') return null;
  try {
    const kept = sessionStorage.getItem(BACK);
    return kept && ARTICLE.test(kept) ? kept : null;
  } catch {
    return null;
  }
}

function forgetCameFrom() {
  try {
    sessionStorage.removeItem(BACK);
  } catch {
    // Nothing was kept.
  }
}

/** Say where *continue* will lead, and offer the way back without it. */
function offerBack() {
  const back = cameFrom();
  document.querySelectorAll('[data-back]').forEach((offer) => {
    offer.hidden = back === null;
    const link = offer.querySelector('a');
    if (back !== null && link) link.href = back;
  });
}

// ---- wiring ---------------------------------------------------------------

function arrive() {
  if (document.prerendering) return;
  bind();
  rememberCameFrom();
  offerBack();
  document.querySelectorAll('[data-close-meter], [data-setup-form]').forEach((element) => { element.hidden = false; });
}

document.addEventListener('click', (event) => {
  const target = event.target;
  const actions = [
    ['[data-close-meter]', close],
    ['[data-setup-development]', development],
    ['[data-setup-continue]', proceed],
  ];
  for (const [selector, act] of actions) {
    const button = target.closest?.(selector);
    if (button) {
      event.preventDefault();
      act(button);
      return;
    }
  }
});

document.addEventListener('submit', (event) => {
  const form = event.target.closest?.('[data-setup-form]');
  if (form) {
    event.preventDefault();
    start(form);
  }
});

document.addEventListener('newsprint:swapped', arrive);
document.addEventListener('prerenderingchange', arrive);
arrive();
