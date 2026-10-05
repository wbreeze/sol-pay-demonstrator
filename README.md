# sol-pay demonstrator

A working site that meters a small set of articles on the Solana blockchain
using [sol-pay](https://github.com/wbreeze/sol-pay) on devnet, against a token
it issues itself.

[`SPEC.md`](SPEC.md) is this site's design, and every platform choice in
[SPEC.md §12](SPEC.md#12-platform) is decided and in service: PHP with Slim 4,
SQLite, the public devnet endpoint, called from the server.

## Why this exists

[sol-pay](https://github.com/wbreeze/sol-pay) is an on-chain metering program
for Solana. A reader's wallet opens a fund, and a meter on that fund for a
site. The site counts page views against the meter and collects what has
accumulated from the fund, up to a limit and until an expiry that the reader
set. The money stays in a fund the reader controls until it is spent, and
nothing anywhere keeps an account of who read what. That repository holds the
program, its specification in
[`wasm-client/SPEC.md`](https://github.com/wbreeze/sol-pay/blob/master/wasm-client/SPEC.md),
and the state diagram the flow follows.

The client library is published as `sol-pay-client`, once per language:

- [npm](https://www.npmjs.com/package/sol-pay-client) — the wasm build, for a
  browser. **This site does not load it**
  ([SPEC.md §12.2](SPEC.md#122-front-end)): the page signs with WebCrypto, and
  the server composes every message that is signed.
- [Packagist](https://packagist.org/packages/wbreeze/sol-pay-client) —
  `wbreeze/sol-pay-client`, the PHP port, **and the one this site
  demonstrates**. It composes the setup transaction that a reader's wallet
  signs, signs `meter_and_settle` with the site authority, reads and decodes
  accounts over RPC, and never sees the reader's wallet key.
- [crates.io](https://crates.io/crates/sol-pay-client) — the Rust crate, which
  a Rust server would take for the same row.

sol-pay ships no application, on purpose. `wasm-client/SPEC.md` §1 names three
screens — `set_meter`, `manage_meter`, `metered_page` — and says they belong to
the integrator. This repository builds them, in PHP, on the PHP port.

It is meant to be read as much as run. Everything sol-pay declines to supply —
RPC, the transaction request a wallet fetches, the browser key and its session,
the decision to meter a request, error attribution, log hygiene — is here, in
one place, in the smallest honest form.

## Reading order

The articles the site meters are also its documentation. Each one is a decision
and the argument for it. In the order that builds:

1. [What a dime has to do](content/MeteredPayEconomics.md) — at a penny,
   pay-per-view cannot come near what advertising earns. At about a dime it
   can, without tracking anyone, if one page view in five is paid for.
1. [The ID of what's paying](content/NoSignInPage.md) — every site that
   monetizes itself reaches for the same shape first: prove who you are, then
   we'll leverage your use. What the meter actually needs to know is never a
   person.
1. [Privacy](content/privacy.md) — not a privacy policy. The list of what this
    site holds about a reader, short enough to print in full.
1. [How to read the inspector](content/ReadingTheInspector.md) — the panel at
    the foot of every page, section by section: what each one says, and what
    the site had to do to be able to say it.
1. [What the limit promises](content/WhatTheLimitPromises.md) — a limit is not
   a reading budget. The site signs every charge alone and can charge up to the
   limit whenever it likes. The limit is the most the reader can lose.
1. [The request nobody made](content/RequestNobodyMade.md) — this site charged
   for an article on a GET, and a browser makes that kind of request whenever
   it likes. Moving the charge to a POST closed a hole and removed the longest
   silent wait on the site.
1. [Two orderings that disagree on purpose](content/TwoOrderings.md) — charging
   records the grant without waiting for the chain. Closing waits for the chain
   and then checks the account before deleting anything. Making the two match
   would put a bug in one of them.
1. [The error log that keeps a reader's spending](content/TheLogs.md) — a
   failed charge returns a number that does not say which program raised it.
   The answer is in the transaction logs, and so is the reader's wallet address
   and spending.
1. [The first transaction, and the one that counted](content/FirstEverOnChain.md)
   — a validator accepted the first transaction this site's server ever built.
   The library's specification had already written down what would count as
   proof, and a transfer was not it.

## Content

`content/*.md` is the source: front matter, then a body in CommonMark plus
tables. `bin/build-content` renders each piece into `var/content/` and refuses
a body carrying its own `<h1>`, because the title is rendered from the front
matter. The index lists the metered pieces, newest first. See
[SPEC.md §10](SPEC.md#10-content).

Dates are written by hand and checked rather than derived. `bin/content-dates`
reads when each piece was added and when it last changed, and complains when a
piece has moved since the date it claims. A commit that changes nothing a
reader sees carries the trailer `Reader-Visible: no` and is skipped.

`bin/render-diff` renders every branch of every template from this working tree
and from a git ref, and names what moved. Exit 1 means something moved, which
is information rather than a failure. It cannot see content: its fixtures carry
string bodies, so nothing in `content/` reaches it.

Both scripts need PHP and the git history together.

## Running it

```
composer install
bin/build-content         # content/*.md into var/content, which is gitignored
bin/devnet-smoke          # airdrop, then one real transaction (see below)
bin/run-dev               # http://localhost:8000
bin/check                 # what CI checks, before CI sees it
```

PHP 8.2 or later, with `sodium`, `pdo_sqlite` and `curl` — all bundled. 8.2 is
where `composer.lock` resolves with `--no-dev`, and CI checks that in a job of
its own.

First-run setup is a screen rather than a command. It creates the demo mint and
the treasury account, calls `initialize_site` once, and writes the resulting
addresses into the configuration that the server reads. See
[SPEC.md §12.0](SPEC.md#120-the-shape).

A reader's setup is a scan from a phone's wallet, and a phone cannot reach
`localhost`. So a development copy has a stand-in:
`NEWSPRINT_DEV_WALLET=1 bin/run-dev` turns on the development wallet, a keypair
in `var/` that signs in place of the phone. The faucet page shows its address
for pasting. It is refused unless the request comes from this machine and the
endpoint is devnet's. See [SPEC.md §12.6](SPEC.md#126-hosting-and-the-development-wallet).

`bin/devnet-smoke` is the first time `SolPay\Core\Tx` meets a validator.
Conformance proves `compile` and `wire` agree byte-for-byte with
`solana-message` and `solana-transaction` on three fixed cases; it cannot prove
a validator accepts the result, because no signature there is real, no
blockhash was ever current, and nothing has paid a fee. This sends one System
transfer and pays one.

**The faucet behind the public endpoint refuses more often than it works.** Its
refusal is generic: a depleted faucet, a per-address limit and a per-IP one all
arrive as the same message. So `bin/devnet-smoke` prints `airdrop refused: …`
and carries on, because the key it uses is kept in `var/` and may already hold
SOL from an earlier run. When the key holds none, the script stops and prints
two ways to fund it by hand — the web faucets, or `solana airdrop` against
devnet. A tenth of a SOL is more than the run needs.

Nothing under `var/` is committed: the SQLite file, the built content, and the
devnet keypairs setup generates. They control nothing of value and the site
says so.

### The sweep, on a schedule

The privacy page promises that a receipt is gone within thirty-five minutes of
the purchase: thirty for the grant, and at most five more until a sweep deletes
the row. Expiry alone deletes nothing. The metering path sweeps on every
charge, but a site where nobody buys anything never reaches that code. A
deployed copy therefore runs `bin/sweep` every five minutes:

```
*/5 * * * *  /path/to/sol-pay-demonstrator/bin/sweep
```

The script is silent when it succeeds, so cron mails only failures.
`bin/sweep --verbose` says what went. `GET /health` reports how long the oldest
expired grant or session has waited, under `sweep`. When `overdue` is true, the
schedule is not running, and the privacy page is making a promise the server
is not keeping.

`bin/run-dev` runs no schedule. On a development copy the charge-time sweep is
enough.

## The endpoints

What this server answers, and the one property worth knowing before reading
any of it: **a GET never meters**
([SPEC.md §7.1](SPEC.md#71-one-charge-per-article-not-per-request)). That is a
property of the table rather than a habit of the handlers. `RouteTest` reads
what every GET closure captured, and a handler that can charge has to have the
metering factory in scope, so a route added tomorrow under a name nobody
predicted is covered too.

`EndpointDocsTest` keeps the tables below honest about which routes exist. It
cannot keep them honest about what the routes do.

### Pages a reader opens

| route | what it is |
| --- | --- |
| `GET /` | the index — the metered pieces, newest first, with teasers (`SPEC.md` §6.1) |
| `GET /a/{slug}` | the article, or the meter in its place. It cannot charge |
| `GET /meter` | `manage_meter`: reachable at any time rather than only at the limit (`SPEC.md` §6) |
| `GET /privacy` | a 301 to `/a/privacy`. `SPEC.md` §10.2 asks that the URL carry the page, not that a second handler render it |
| `GET /faucet` | the demo's faucet: a form that takes a pasted wallet address (`SPEC.md` §4.3) |
| `GET /setup` | first run, once per deployment (`SPEC.md` §12.0) |

### The charging path

Every one of these is a POST, and each answers with HTML.

| route | what it is |
| --- | --- |
| `POST /a/{slug}` | the page view. The only route that can charge for an article (`SPEC.md` §7) |
| `POST /a/{slug}/confirm` | what became of that charge, asked on a later request (`SPEC.md` §7.3) |
| `POST /meter/advance` | seven views in one instruction, so the collection threshold is reached on purpose (`SPEC.md` §7.4) |
| `POST /faucet` | sends the grant to the pasted address, once per address, and refuses a request that came from another site's page (`SPEC.md` §4.3) |
| `POST /setup` | runs the provisioner once, and refuses a request that came from another site's page (`SPEC.md` §12.0) |

### Called by the page's own scripts

The key proof, the key-signed close and the setup scan are the page's.

| route | what it is | answers with |
| --- | --- | --- |
| `POST /key/nonce` | a nonce for a key proof, forgotten at its first use (`SPEC.md` §5.2) | JSON |
| `POST /key/prove` | checks a key proof against the meter it names, and binds a session (`SPEC.md` §5.3) | JSON, and the cookie |
| `POST /meter/close/prepare` | compiles `close_meter` for this browser's key to sign (`SPEC.md` §5.4) | JSON |
| `POST /meter/close` | countersigns and sends the close, then `SPEC.md` §10.4's erasure | JSON carrying the inspector's sections (`SPEC.md` §9.2), and the cookie cleared |
| `POST /meter/setup` | records a pending setup, renewal or deposit, and answers its Solana Pay link (`SPEC.md` §6.3) | JSON |
| `POST /meter/setup/continue` | *continue*: a fresh key proof against the meter the setup named, and a session (`SPEC.md` §6.3) | JSON, and the cookie |
| `POST /pay/{id}/development` | the development wallet signs the pending setup in place of a phone; loopback and devnet only (`SPEC.md` §12.6) | JSON |
| `GET /inspector/panel` | the panel's sections for a page that read nothing (`SPEC.md` §9) | a fragment with `X-Fragment: 1`, a page without it |
| `GET /inspector/event/{signature}` | one decoded event, on demand (`SPEC.md` §9) | JSON |

### Called by the reader's wallet

A Solana Pay transaction request (`SPEC.md` §6.3). The wallet fetches these,
not the page, so they answer any origin.

| route | what it is | answers with |
| --- | --- | --- |
| `GET /pay/{id}` | the label and icon the wallet shows before it asks | JSON |
| `POST /pay/{id}` | the setup transaction composed for the wallet's account, unsigned, and the sentence it does | JSON |

### Operator-facing

| route | what it is |
| --- | --- |
| `GET /health` | PHP version, the three extensions, and the age of the oldest expired row under `sweep`. Keyed to no reader, deliberately (`SPEC.md` §10.4) |

An unknown path is a 404 rendered as a page. A stale link and a browser asking
for `/favicon.ico` are ordinary things, and Slim's default for both is a stack
trace in the log with a bare error page in the browser — which does not belong
on a site whose whole argument is that you can read what it is doing.

## Continuous integration

`.github/workflows/ci.yml`, on every push and pull request.

- **`test`** — a matrix of 8.2 through 8.5, the range PHPUnit 11 will run on.
  8.5 is at the top because deprecations surface there first;
  `src/Chain/Rpc.php` names the one it closes.
- **`floor`** — 8.2, installing `--no-dev`, parsing every committed file and
  booting `php -S`. Running the site is a different promise from the suite
  passing, and this is the only place it can be checked.
- **`phpstan`** — level 5 over `src`, `tests`, `config`, `public/index.php` and
  the `bin/` scripts, from a PHAR pinned in the workflow and fetched from its
  release rather than carried as a `require-dev` entry, so `composer.lock`
  stays a record of what the site needs and a floating analyser cannot turn CI
  red on a morning when nobody committed anything. `templates/` is excluded:
  `View` renders with `extract()`, so every template variable is undefined to
  an analyser, and `TemplateRenderTest` covers that side under a strict error
  handler instead.

`bin/check` runs all of that here, in CI's order: validate, install, build the
content, PHPUnit, the pinned analyser, and the boot check.

```
bin/check                        all of it
bin/check tests --filter Shell   phpunit only; the rest of the line goes to it
bin/check analyse                the analyser only
bin/check boot                   the boot check only
```

It reads the analyser's version out of `ci.yml` and the floor out of
`composer.json` rather than repeating either, so a pin bumped in one place
cannot quietly disagree with a copy in the script, and it caches the PHAR under
`var/`. What it cannot reproduce is the matrix — this machine has one PHP — so
it runs the analyser a second time with `phpVersion` set to the floor, which is
a language-level check and not a run. Green here means push and read the run.

`.github/workflows/canary.yml`, daily, for the failures that arrive without a
commit. Two of them, and they mean different things:

- **`bin/devnet-canary`** — the sol-pay program is deployed and executable at
  the configured id, and the endpoint answers. Read-only, unfunded, and it
  exits 75 rather than 1 when devnet simply did not answer: the rate limit of
  [SPEC.md §12.4](SPEC.md#124-rpc) is weather, not news, and a monitor
  that cannot tell them apart is a monitor everyone learns to ignore.
- **`bin/upstream-drift`** — whether the published `sol-pay-client` has moved
  past the version that `composer.lock` pins. An example pinned to a version
  nobody installs still passes its tests and still teaches the wrong API. Exits
  1: news, not an outage.

Both run by hand too, and neither needs a key. A failure opens one issue and
comments on it thereafter rather than filing a fresh one every morning.

`bin/devnet-smoke` is *not* on the schedule: it pays a fee, and a scheduled job
that spends is a scheduled job someone eventually turns off. It is available
from the canary workflow's manual run, which reads the authority keypair from a
`DEVNET_AUTHORITY_KEYPAIR` secret and deletes it afterwards; without the secret
that job skips.

## Licence

Dual licensed under either of [Apache License, Version 2.0](LICENSE-APACHE)
or the [MIT license](LICENSE-MIT), at your option — matching sol-pay.
