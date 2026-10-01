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
 * 3. **The development stand-in** (slice 2 only): show this browser's public
 *    key, and take a meter address that `bin/fund-trials hand` renewed to it.
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

// ---- 3. the development stand-in -----------------------------------------

async function trial() {
  const shown = document.querySelector('[data-key-trial-key]');
  if (!shown || shown.dataset.shown) return;
  shown.dataset.shown = '1';
  shown.textContent = await publicKey(await keyPair());
}

async function hold(form) {
  const status = form.querySelector('[data-key-trial-status]');
  const meter = new FormData(form).get('meter')?.toString().trim();
  if (!meter) return;
  await write('meter', meter);
  say(status, 'Proving this browser\'s key against that meter…');
  const { status: code, body } = await prove(meter);
  if (body.ok) {
    location.reload();
    return;
  }
  say(status, body.message || `The proof was refused (${code}).`);
}

// ---- wiring ---------------------------------------------------------------

function arrive() {
  if (document.prerendering) return;
  bind();
  trial();
  document.querySelectorAll('[data-close-meter]').forEach((button) => { button.hidden = false; });
}

document.addEventListener('click', (event) => {
  const button = event.target.closest?.('[data-close-meter]');
  if (button) {
    event.preventDefault();
    close(button);
  }
});

document.addEventListener('submit', (event) => {
  const form = event.target.closest?.('[data-key-trial-form]');
  if (form) {
    event.preventDefault();
    hold(form);
  }
});

document.addEventListener('newsprint:swapped', arrive);
document.addEventListener('prerenderingchange', arrive);
arrive();
