# sol-pay demonstrator

A working site that meters a small set of articles with
[sol-pay](https://github.com/wbreeze/sol-pay), on devnet, against a token it
issues itself.

`SPEC.md` is the design, and §12's platform choices are all closed: PHP with
Slim 4, SQLite, the public devnet endpoint, called from the server.

## Why this exists

`sol-pay-client` is a library that ships no application on purpose. Its spec
names three screens — `set_meter`, `manage_meter`, `metered_page` — and says
they belong to the integrator. This repository builds them.

It is meant to be read as much as run. Everything sol-pay declines to supply —
RPC, the wallet adapter, the session, the viewer-to-wallet map, the decision to
meter a request, error attribution, log hygiene — is here, in one place, in the
smallest honest form.

## Status

| | |
| --- | --- |
| spec | draft, 2026-09-02, revised through 2026-09-05 |
| cluster | devnet |
| token | a demo mint, six decimals, worth nothing |
| implementation | server skeleton, store and chain plumbing; no screens yet |

## Running it

```
composer install
bin/devnet-smoke          # airdrop, then one real transaction (see below)
php -S localhost:8000 -t public
bin/check                 # what CI checks, before CI sees it
```

PHP 8.2 or later, with `sodium`, `pdo_sqlite` and `curl` — all bundled.

The floor was 8.1 until 2026-09-08, and CI is what moved it. The *constraints*
resolved on 8.1, so the claim looked true; the committed lock did not, because
it is resolved on 8.5 and takes the newest of each — `symfony/yaml` 7.4 and,
transitively through commonmark, `nette/utils` 4.1, both of which require 8.2.
A reader on stock 8.1 following the line above got exit 2 from `composer
install`, and nothing here could have told them apart from a promise that held.
Pinning the lock to 8.1 would have meant holding two packages back
indefinitely, and `require-dev` could not have followed in any case: PHPUnit 11
needs 8.2. 8.1 has been end-of-life since December 2025, so the floor moved to
where the lock already was.

`bin/devnet-smoke` is the first time `SolPay\Core\Tx` meets a validator.
Conformance proves `compile` and `wire` agree byte-for-byte with
`solana-message` and `solana-transaction` on three fixed cases; it cannot prove
a validator accepts the result, because no signature there is real, no
blockhash was ever current, and nothing has paid a fee. This sends one System
transfer and pays one.

Nothing under `var/` is committed: the SQLite file, and the devnet keypairs
setup generates. They control nothing of value and the site says so.

## Continuous integration

`.github/workflows/ci.yml`, on every push and pull request. The matrix is
8.2 through 8.5 because that is the range PHPUnit 11 will run on, plus a
separate 8.2 floor job that installs `--no-dev`, parses every committed file
and boots `php -S` — the floor above is a promise to someone who only wants to
run the site, which is a different promise from "the suite passes", and it is
checked in the only place it can be. That job is what moved the floor off 8.1.
8.5 is at the top on purpose: `Rpc::call` names the deprecation that sol-pay's
`php-conformance.yml` would have caught and this repository had nowhere to.

The `phpstan` job runs level 5 over `src`, `tests`, `config`, `public/index.php`
and the `bin/` scripts. It is there because the same shape of bug got through
`php -l` and the suite three times: a bareword array key, `$blocked->reason` on
a class carrying a `kind` and a `__toString()`, and a `Cause` cast to a string
it deliberately declines to become. Each is a well-formed program that is wrong
on a branch no test reaches — §8's failure paths are rare by construction, which
is exactly why nothing exercises them. `phpstan.neon` records all three and the
reason for the level. `templates/` is excluded on purpose: `View` renders with
`extract()`, so every template variable is undefined to an analyser;
`TemplateRenderTest` covers that side under a strict error handler instead.

The analyser is a pinned PHAR fetched from its release rather than a
`require-dev` entry — `composer.lock` stays a record of what the *site* needs,
and a floating analyser is one that can turn CI red on a morning when nobody
committed anything.

`bin/check` runs all of that here, in CI's order: validate, install, build the
content, PHPUnit, the pinned analyser, the vendored assets, and the boot check.

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

```
bin/devnet-canary     the sol-pay program is deployed and executable at the
                      configured id, and the endpoint answers. Read-only,
                      unfunded, and it exits 75 rather than 1 when devnet
                      simply did not answer — §12.4's rate limit is weather,
                      not news, and a monitor that cannot tell them apart is
                      a monitor everyone learns to ignore.

bin/upstream-drift    whether the published `sol-pay-client` has moved past
                      what this repository pins — `composer.lock` for the
                      server half, `public/vendor/README.md`'s provenance
                      table for the two browser files. An example pinned to a
                      version nobody installs still passes its tests and still
                      teaches the wrong API. Exits 1: news, not an outage.

                      The same script asks one more question, and it is not a
                      version question. `sol-pay-client` returns instructions
                      already shaped like `@solana/kit`'s `IInstruction`, and
                      `public/assets/tx.js` hands them straight to
                      `appendTransactionMessageInstructions` — an agreement
                      that neither package declares, because sol-pay's wasm
                      client has no JavaScript dependency at all and this
                      repository chose kit for itself. So the script reads the
                      kit range `sol-pay-client` publishes in
                      `peerDependencies` for the exact version vendored here,
                      and checks the committed kit against it. Outside the
                      range exits 2, louder than 1, because that one does not
                      fail at install — it fails at `compileTransaction`, in a
                      browser, with a wallet prompt already open. Until
                      `sol-pay-client` publishes such a range the check says so
                      and changes nothing.
```

Both run by hand too, and neither needs a key. A failure opens one issue and
comments on it thereafter rather than filing a fresh one every morning.

`bin/devnet-smoke` is *not* on the schedule: it pays a fee, and a scheduled job
that spends is a scheduled job someone eventually turns off. It is available
from the canary workflow's manual run, which reads the authority keypair from a
`DEVNET_AUTHORITY_KEYPAIR` secret and deletes it afterwards; without the secret
that job skips.

## Reading order

- `SPEC.md` §1–§2 — what this is and what it has to prove
- `SPEC.md` §7 — the metering decision, which is the part sol-pay leaves to the
  site and the part an integrator comes here for
- `SPEC.md` §12 — the platform choices still open

## Licence

Dual licensed under either of Apache License, Version 2.0 or the MIT license,
at your option — matching sol-pay.
