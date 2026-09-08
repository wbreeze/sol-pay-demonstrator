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
```

PHP 8.1 or later, with `sodium`, `pdo_sqlite` and `curl` — all bundled. The
suite (`composer test`) needs 8.2, because PHPUnit 11 does; `composer install
--no-dev` resolves on the floor, which is how a reader on 8.1 would install.

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
separate 8.1 job that installs `--no-dev`, parses every committed file and
boots `php -S` — the floor this README promises is a promise to someone who
only wants to run the site, and it is checked in the only place it can be.
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
committed anything. To run the same version by hand:

```
curl -fsSL -o /tmp/phpstan.phar \
  https://github.com/phpstan/phpstan/releases/download/2.2.7/phpstan.phar
php /tmp/phpstan.phar analyse
```

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
                      teaches the wrong API.
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
